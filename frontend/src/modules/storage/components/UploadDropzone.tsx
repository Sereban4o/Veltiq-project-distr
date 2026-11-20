import { useRef } from 'react'
import { Box, Button } from '@mui/material'
import { UploadFile as UploadIcon, DriveFolderUpload as FolderIcon } from '@mui/icons-material'
import { useFolderDrop, type DroppedFile } from '../hooks/useFolderDrop'

interface Props {
  disabled?: boolean
  onFiles: (files: DroppedFile[]) => void
}

/**
 * Wraps a region so dropped files/folders are uploaded, and exposes buttons to
 * pick files or a whole folder. Renders a drop overlay while dragging.
 */
export default function UploadDropzone({ disabled, onFiles }: Props) {
  const fileInput = useRef<HTMLInputElement>(null)
  const folderInput = useRef<HTMLInputElement>(null)
  const { isDragging, dropHandlers } = useFolderDrop(onFiles)

  const openFilePicker = () => fileInput.current?.click()
  const openFolderPicker = () => folderInput.current?.click()

  return (
    <Box
      {...dropHandlers}
      sx={{ position: 'relative', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}
    >
      <input
        ref={fileInput}
        type="file"
        multiple
        hidden
        onChange={(e) => {
          const files = Array.from(e.target.files ?? []).map((file) => ({ file, relativePath: file.name }))
          if (files.length) onFiles(files)
          e.target.value = ''
        }}
      />
      <input
        ref={folderInput}
        type="file"
        hidden
        multiple
        // @ts-expect-error non-standard but widely supported attribute
        webkitdirectory="true"
        directory=""
        onChange={(e) => {
          const files = Array.from(e.target.files ?? []).map((file) => ({
            file,
            relativePath: (file as any).webkitRelativePath || file.name,
          }))
          if (files.length) onFiles(files)
          e.target.value = ''
        }}
      />

      <Box sx={{ display: 'flex', gap: 1, mb: 1 }}>
        <Button
          size="small"
          startIcon={<UploadIcon />}
          disabled={disabled}
          onClick={openFilePicker}
          sx={{ textTransform: 'none' }}
        >
          Загрузить файлы
        </Button>
        <Button
          size="small"
          startIcon={<FolderIcon />}
          disabled={disabled}
          onClick={openFolderPicker}
          sx={{ textTransform: 'none' }}
        >
          Загрузить папку
        </Button>
      </Box>

      {isDragging && (
        <Box
          sx={{
            position: 'absolute',
            inset: 0,
            zIndex: 5,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            bgcolor: 'rgba(25, 118, 210, 0.08)',
            border: '2px dashed',
            borderColor: 'primary.main',
            borderRadius: 1,
            pointerEvents: 'none',
          }}
        >
          <Box sx={{ typography: 'body1', color: 'primary.main', fontWeight: 600 }}>Отпустите файлы для загрузки</Box>
        </Box>
      )}
    </Box>
  )
}
