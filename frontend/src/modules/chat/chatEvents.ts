type OpenChatListener = (chatId: number) => void

const listeners = new Set<OpenChatListener>()

/** Subscribe to "open this chat" requests (e.g. a notification click). */
export function onOpenChat(listener: OpenChatListener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/** Ask the chat widget to open the given chat. */
export function requestOpenChat(chatId: number): void {
  listeners.forEach((listener) => listener(chatId))
}
