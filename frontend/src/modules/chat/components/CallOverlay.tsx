import { useEffect, useRef } from 'react'
import { Alert, Box, Button, Dialog, IconButton, Stack, Typography } from '@mui/material'
import {
  Call as CallIcon,
  CallEnd as HangupIcon,
  Mic as MicIcon,
  MicOff as MicOffIcon,
  Videocam as CamIcon,
  VideocamOff as CamOffIcon,
} from '@mui/icons-material'
import type { CallApi } from '../call/useCall'

interface Props {
  call: CallApi
  peerName: string
}

/** Full-screen overlay for an active/incoming/outgoing 1:1 call. */
export default function CallOverlay({ call, peerName }: Props) {
  const remoteRef = useRef<HTMLVideoElement | null>(null)
  const localRef = useRef<HTMLVideoElement | null>(null)

  useEffect(() => {
    if (remoteRef.current) remoteRef.current.srcObject = call.remoteStream
  }, [call.remoteStream, call.phase])

  useEffect(() => {
    if (localRef.current) localRef.current.srcObject = call.localStream
  }, [call.localStream, call.phase])

  if (call.phase === 'idle') return null

  return (
    <Dialog open fullScreen>
      <Box sx={{ position: 'relative', width: '100%', height: '100%', bgcolor: '#000', color: '#fff' }}>
        {call.phase === 'active' && call.remoteStream ? (
          <Box
            component="video"
            ref={remoteRef}
            autoPlay
            playsInline
            sx={{ width: '100%', height: '100%', objectFit: 'contain', bgcolor: '#000' }}
          />
        ) : (
          <Stack sx={{ height: '100%' }} alignItems="center" justifyContent="center" spacing={2}>
            <CallIcon sx={{ fontSize: 64, opacity: 0.7 }} />
            <Typography variant="h6">{peerName}</Typography>
            <Typography variant="body2" color="grey.400">
              {call.phase === 'incoming' ? 'Входящий звонок…' : call.phase === 'outgoing' ? 'Вызов…' : 'Соединение…'}
            </Typography>
          </Stack>
        )}

        {call.localStream && (
          <Box
            component="video"
            ref={localRef}
            autoPlay
            playsInline
            muted
            sx={{
              position: 'absolute',
              right: 16,
              bottom: 96,
              width: 160,
              height: 120,
              objectFit: 'cover',
              borderRadius: 2,
              border: '1px solid rgba(255,255,255,0.3)',
              bgcolor: '#111',
              transform: 'scaleX(-1)',
            }}
          />
        )}

        {call.error && (
          <Alert severity="error" sx={{ position: 'absolute', top: 16, left: 16, right: 16 }}>
            {call.error}
          </Alert>
        )}

        <Stack
          direction="row"
          spacing={2}
          sx={{ position: 'absolute', bottom: 24, left: 0, right: 0, justifyContent: 'center' }}
        >
          {call.phase === 'incoming' ? (
            <>
              <Button
                variant="contained"
                color="success"
                startIcon={<CallIcon />}
                onClick={call.accept}
                sx={{ textTransform: 'none' }}
              >
                Принять
              </Button>
              <Button
                variant="contained"
                color="error"
                startIcon={<HangupIcon />}
                onClick={call.decline}
                sx={{ textTransform: 'none' }}
              >
                Отклонить
              </Button>
            </>
          ) : (
            <>
              <IconButton onClick={call.toggleMute} sx={{ color: '#fff', bgcolor: 'rgba(255,255,255,0.15)' }}>
                {call.muted ? <MicOffIcon /> : <MicIcon />}
              </IconButton>
              <IconButton onClick={call.toggleCamera} sx={{ color: '#fff', bgcolor: 'rgba(255,255,255,0.15)' }}>
                {call.cameraOff ? <CamOffIcon /> : <CamIcon />}
              </IconButton>
              <IconButton
                onClick={call.hangup}
                sx={{ color: '#fff', bgcolor: 'error.main', '&:hover': { bgcolor: 'error.dark' } }}
              >
                <HangupIcon />
              </IconButton>
            </>
          )}
        </Stack>
      </Box>
    </Dialog>
  )
}
