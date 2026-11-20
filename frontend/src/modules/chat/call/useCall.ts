import { useCallback, useEffect, useRef, useState } from 'react'
import apiClient from '@/modules/shared/api/apiClient'
import { useAuth } from '@/modules/auth/store/authStore'
import { getEcho } from '../realtime'

export type CallPhase = 'idle' | 'outgoing' | 'incoming' | 'active'

export interface CallApi {
  phase: CallPhase
  localStream: MediaStream | null
  remoteStream: MediaStream | null
  muted: boolean
  cameraOff: boolean
  peerName: string | null
  error: string | null
  start: (video: boolean) => void
  accept: () => void
  decline: () => void
  hangup: () => void
  toggleMute: () => void
  toggleCamera: () => void
}

interface SignalPayload {
  chat_id: number
  from: number
  to: number | null
  type: string
  call_id: string
  payload: Record<string, unknown>
  name?: string
}

function rtcConfig(): RTCConfiguration {
  const env = import.meta.env as Record<string, string | undefined>
  const iceServers: RTCIceServer[] = [{ urls: env.VITE_STUN_URL || 'stun:stun.l.google.com:19302' }]

  if (env.VITE_TURN_URL) {
    iceServers.push({
      urls: env.VITE_TURN_URL,
      username: env.VITE_TURN_USERNAME,
      credential: env.VITE_TURN_CREDENTIAL,
    })
  }

  return { iceServers }
}

/**
 * 1:1 audio/video calls over WebRTC. Signaling (offer/answer/ICE/hangup) is
 * relayed through the chat's private Reverb channel via `POST /chat/{id}/call/signal`.
 * Calls are offered only for direct chats where the peer id is known.
 */
export function useCall(chatId: number | null, peerId: number | null): CallApi {
  const { token, user } = useAuth()
  const [phase, setPhase] = useState<CallPhase>('idle')
  const [localStream, setLocalStream] = useState<MediaStream | null>(null)
  const [remoteStream, setRemoteStream] = useState<MediaStream | null>(null)
  const [muted, setMuted] = useState(false)
  const [cameraOff, setCameraOff] = useState(false)
  const [peerName, setPeerName] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const pcRef = useRef<RTCPeerConnection | null>(null)
  const localRef = useRef<MediaStream | null>(null)
  const callIdRef = useRef<string | null>(null)
  const pendingOfferRef = useRef<RTCSessionDescriptionInit | null>(null)
  const queuedIceRef = useRef<RTCIceCandidateInit[]>([])
  const wantVideoRef = useRef(true)
  const phaseRef = useRef<CallPhase>('idle')
  phaseRef.current = phase

  const cleanup = useCallback(() => {
    try {
      pcRef.current?.close()
    } catch {
      /* ignore */
    }
    pcRef.current = null
    localRef.current?.getTracks().forEach((track) => track.stop())
    localRef.current = null
    queuedIceRef.current = []
    pendingOfferRef.current = null
    callIdRef.current = null
    setLocalStream(null)
    setRemoteStream(null)
    setMuted(false)
    setCameraOff(false)
    setPeerName(null)
    setPhase('idle')
  }, [])

  const sendSignal = useCallback(
    async (type: string, payload: Record<string, unknown> = {}) => {
      if (!chatId || !callIdRef.current) return
      try {
        await apiClient.post(`/v1/chat/${chatId}/call/signal`, {
          type,
          call_id: callIdRef.current,
          to: peerId,
          payload,
        })
      } catch {
        /* signaling is best-effort */
      }
    },
    [chatId, peerId],
  )

  const ensureLocalStream = useCallback(async (video: boolean) => {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video })
    localRef.current = stream
    setLocalStream(stream)
    return stream
  }, [])

  const createPeer = useCallback(() => {
    const pc = new RTCPeerConnection(rtcConfig())
    const stream = localRef.current
    if (stream) stream.getTracks().forEach((track) => pc.addTrack(track, stream))

    pc.onicecandidate = (event) => {
      if (event.candidate) void sendSignal('ice', { candidate: event.candidate.toJSON() })
    }
    pc.ontrack = (event) => {
      const [incoming] = event.streams
      setRemoteStream(incoming ?? new MediaStream([event.track]))
    }
    pc.onconnectionstatechange = () => {
      if (pc.connectionState === 'failed' || pc.connectionState === 'closed') cleanup()
    }
    pcRef.current = pc
    return pc
  }, [sendSignal, cleanup])

  const flushIce = useCallback(async () => {
    const pc = pcRef.current
    if (!pc || !pc.remoteDescription) return
    for (const candidate of queuedIceRef.current) {
      try {
        await pc.addIceCandidate(candidate)
      } catch {
        /* ignore */
      }
    }
    queuedIceRef.current = []
  }, [])

  const start = useCallback(
    (video: boolean) => {
      if (!chatId || !peerId || phaseRef.current !== 'idle') return
      setError(null)
      wantVideoRef.current = video
      callIdRef.current = crypto.randomUUID()
      setPhase('outgoing')

      void (async () => {
        try {
          await ensureLocalStream(video)
        } catch {
          setError('Нет доступа к камере/микрофону')
          cleanup()
          return
        }
        void sendSignal('invite', { video })
        const pc = createPeer()
        const offer = await pc.createOffer()
        await pc.setLocalDescription(offer)
        void sendSignal('offer', { sdp: pc.localDescription?.toJSON() })
      })()
    },
    [chatId, peerId, ensureLocalStream, createPeer, sendSignal, cleanup],
  )

  const accept = useCallback(() => {
    const offer = pendingOfferRef.current
    if (!offer) return
    setError(null)

    void (async () => {
      try {
        await ensureLocalStream(wantVideoRef.current)
      } catch {
        setError('Нет доступа к камере/микрофону')
        void sendSignal('decline')
        cleanup()
        return
      }
      const pc = createPeer()
      await pc.setRemoteDescription(offer)
      const answer = await pc.createAnswer()
      await pc.setLocalDescription(answer)
      void sendSignal('answer', { sdp: pc.localDescription?.toJSON() })
      void sendSignal('accept')
      await flushIce()
      setPhase('active')
    })()
  }, [ensureLocalStream, createPeer, sendSignal, flushIce, cleanup])

  const decline = useCallback(() => {
    void sendSignal('decline')
    cleanup()
  }, [sendSignal, cleanup])

  const hangup = useCallback(() => {
    void sendSignal('hangup')
    cleanup()
  }, [sendSignal, cleanup])

  const toggleMute = useCallback(() => {
    const track = localRef.current?.getAudioTracks()[0]
    if (!track) return
    track.enabled = !track.enabled
    setMuted(!track.enabled)
  }, [])

  const toggleCamera = useCallback(() => {
    const track = localRef.current?.getVideoTracks()[0]
    if (!track) return
    track.enabled = !track.enabled
    setCameraOff(!track.enabled)
  }, [])

  useEffect(() => {
    if (!chatId || !peerId || !token || !user?.id) return

    const echo = getEcho(token)
    const channel = echo.private(`chat.${chatId}`)

    const handle = (raw: SignalPayload) => {
      if (!raw || raw.chat_id !== chatId || raw.from === user.id) return
      if (raw.from !== peerId) return
      if (raw.to != null && raw.to !== user.id) return

      if (raw.type === 'invite') {
        if (phaseRef.current !== 'idle') return
        callIdRef.current = raw.call_id
        wantVideoRef.current = Boolean(raw.payload?.video)
        setPeerName(raw.name ?? null)
        setPhase('incoming')
        return
      }

      if (raw.type === 'offer') {
        pendingOfferRef.current = (raw.payload?.sdp as RTCSessionDescriptionInit) ?? null
        return
      }

      if (raw.type === 'answer') {
        const pc = pcRef.current
        const sdp = raw.payload?.sdp as RTCSessionDescriptionInit | undefined
        if (pc && sdp && !pc.currentRemoteDescription) {
          void pc
            .setRemoteDescription(sdp)
            .then(flushIce)
            .then(() => setPhase('active'))
            .catch(() => undefined)
        }
        return
      }

      if (raw.type === 'ice') {
        const candidate = raw.payload?.candidate as RTCIceCandidateInit | undefined
        if (!candidate) return
        const pc = pcRef.current
        if (pc && pc.remoteDescription) {
          void pc.addIceCandidate(candidate).catch(() => undefined)
        } else {
          queuedIceRef.current.push(candidate)
        }
        return
      }

      if (raw.type === 'decline' || raw.type === 'hangup') {
        cleanup()
      }
    }

    channel.listen('CallSignal', handle)

    return () => {
      try {
        channel.stopListening('CallSignal')
      } catch {
        /* ignore */
      }
    }
  }, [chatId, peerId, token, user?.id, flushIce, cleanup])

  // Drop the call when navigating away from the chat.
  useEffect(() => () => cleanup(), [chatId, cleanup])

  return {
    phase,
    localStream,
    remoteStream,
    muted,
    cameraOff,
    peerName,
    error,
    start,
    accept,
    decline,
    hangup,
    toggleMute,
    toggleCamera,
  }
}
