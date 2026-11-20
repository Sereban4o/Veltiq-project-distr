import { useState, useEffect, useMemo } from 'react'
import { Outlet, useNavigate, useLocation } from 'react-router-dom'
import {
  Box,
  Drawer,
  AppBar,
  Toolbar,
  Typography,
  IconButton,
  List,
  ListItem,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Divider,
  Avatar,
  Menu,
  MenuItem,
  Tooltip,
  useTheme,
  useMediaQuery,
  CircularProgress,
} from '@mui/material'
import {
  Menu as MenuIcon,
  Close as CloseIcon,
  Dashboard as DashboardIcon,
  Description as DescriptionIcon,
  ExitToApp as LogoutIcon,
  Person as PersonIcon,
  ChevronLeft as ChevronLeftIcon,
  ChevronRight as ChevronRightIcon,
  Download as DownloadIcon,
  MailOutline as MailIcon,
  FolderOpen as FolderIcon,
  BusinessCenter as CrmIcon,
  CalendarMonth as CalendarIcon,
  Notifications as NotificationsIcon,
  Search as SearchIcon,
  Timeline as TimelineIcon,
  Bolt as AutomationIcon,
  AccessTime as TimesheetIcon,
  AutoAwesome as AssistantIcon,
  Extension as WidgetsIcon,
  Brightness4 as DarkModeIcon,
  Brightness7 as LightModeIcon,
} from '@mui/icons-material'
import { useAuthContext } from '@/modules/auth/hooks/useAuth'
import { useAuth } from '@/modules/auth/store/authStore'
import ChatWidget from '@/modules/chat/components/ChatWidget'
import ToastHost from '@/modules/shared/components/ToastHost'
import OfflineBanner from '@/modules/shared/components/OfflineBanner'
import BrandFooter from '@/modules/shared/components/BrandFooter'
import NotificationPanel from '@/modules/shared/components/NotificationPanel'
import SearchDialog from '@/modules/search/components/SearchDialog'
import { useNotificationRealtime } from '@/modules/notifications/realtime'
import { useThemeStore } from '@/modules/shared/store/themeStore'
import { useBrandingStore } from '@/modules/shared/branding/brandingStore'
import { useWidgetsStore } from '@/modules/widgets/store'
import SidebarCalendar from '@/modules/calendar/components/SidebarCalendar'

const drawerWidth = 260
const collapsedDrawerWidth = 56
const chatPanelWidth = 400

interface MenuItem {
  key: string
  text: string
  icon: React.ReactElement
  path: string
}

const tasksbaseMenuItems: MenuItem[] = [
  { key: 'board', text: 'Канбан-доска', icon: <DashboardIcon />, path: '/' },
  { key: 'timeline', text: 'Дорожная карта', icon: <TimelineIcon />, path: '/timeline' },
  { key: 'automations', text: 'Автоматизации', icon: <AutomationIcon />, path: '/automations' },
  { key: 'timesheet', text: 'Табель', icon: <TimesheetIcon />, path: '/timesheet' },
  { key: 'calendar', text: 'Календарь', icon: <CalendarIcon />, path: '/calendar' },
  { key: 'crm', text: 'CRM', icon: <CrmIcon />, path: '/crm' },
  { key: 'mail', text: 'Почта', icon: <MailIcon />, path: '/mail' },
  { key: 'files', text: 'Файлы', icon: <FolderIcon />, path: '/files' },
  { key: 'assistant', text: 'Ассистент', icon: <AssistantIcon />, path: '/assistant' },
  { key: 'widgets', text: 'Виджеты', icon: <WidgetsIcon />, path: '/widgets' },
  { key: 'downloads', text: 'Скачать приложения', icon: <DownloadIcon />, path: '/downloads' },
]

const knowledgebaseMenuItems: MenuItem[] = [
  { key: 'knowledgebase', text: 'Пространства', icon: <DescriptionIcon />, path: '/knowledgebase' },
]

/** Applies runtime branding: hides configured items and honours `menu.order`. */
function applyMenuBranding(items: MenuItem[], order: string[], hidden: string[]): MenuItem[] {
  const visible = items.filter((item) => !hidden.includes(item.key))
  if (order.length === 0) return visible

  const rank = new Map(order.map((key, index) => [key, index]))

  return [...visible].sort((a, b) => {
    const ra = rank.has(a.key) ? (rank.get(a.key) as number) : Number.MAX_SAFE_INTEGER
    const rb = rank.has(b.key) ? (rank.get(b.key) as number) : Number.MAX_SAFE_INTEGER
    return ra - rb
  })
}

function MenuSection({
  items,
  labels,
  location,
  navigate,
  isMobile,
  sidebarOpen,
}: {
  items: MenuItem[]
  labels: Record<string, string>
  location: ReturnType<typeof useLocation>
  navigate: ReturnType<typeof useNavigate>
  isMobile: boolean
  sidebarOpen: boolean
}) {
  if (!items.length) return null

  return (
    <List sx={{ px: 1 }}>
      {items.map((item) => {
        const text = labels[item.key] || item.text
        const isActive = item.path === '/' ? location.pathname === item.path : location.pathname.startsWith(item.path)

        return (
          <Tooltip key={item.key} title={text} placement="right" disableHoverListener={sidebarOpen || isMobile} arrow>
            <ListItem disablePadding sx={{ display: 'block', mb: 0.5 }}>
              <ListItemButton
                selected={isActive}
                onClick={() => {
                  navigate(item.path)
                  if (isMobile) setMobileOpen(false)
                }}
                sx={{
                  borderRadius: 10,
                  justifyContent: sidebarOpen && !isMobile ? 'initial' : 'center',
                  px: sidebarOpen && !isMobile ? 2.5 : 1,
                  minHeight: 44,
                  color: '#ffffff',
                }}
              >
                <ListItemIcon
                  sx={{
                    minWidth: 40,
                    color: isActive ? '#ffffff' : 'rgba(255, 255, 255, 0.7)',
                    justifyContent: sidebarOpen && !isMobile ? 'initial' : 'center',
                    marginRight: 0,
                  }}
                >
                  {item.icon}
                </ListItemIcon>
                <ListItemText
                  primary={text}
                  sx={{
                    color: '#ffffff',
                    ...(sidebarOpen && !isMobile ? {} : { display: 'none' }),
                    '& .MuiListItemText-primary': {
                      fontWeight: 500,
                    },
                  }}
                />
              </ListItemButton>
            </ListItem>
          </Tooltip>
        )
      })}
    </List>
  )
}

let setMobileOpen: (open: boolean) => void

export default function AppLayout() {
  const theme = useTheme()
  const navigate = useNavigate()
  const location = useLocation()
  const { user, logout } = useAuthContext()
  const { loading } = useAuth()
  const { mode: themeMode, toggle: toggleTheme } = useThemeStore()
  const branding = useBrandingStore((state) => state.branding)
  const brandLogo = branding?.logo || '/veltiq-icon.svg'
  const brandName = branding?.appName || 'Veltiq project'
  const widgetCount = useWidgetsStore((state) => state.widgets.length)
  const loadWidgets = useWidgetsStore((state) => state.load)

  useEffect(() => {
    void loadWidgets()
  }, [loadWidgets])

  const tasksbaseItems = useMemo(
    () => applyMenuBranding(tasksbaseMenuItems, branding?.menu.order ?? [], branding?.menu.hidden ?? []),
    [branding],
  )

  // The «Виджеты» entry only makes sense once at least one widget is configured.
  const visibleTasksbaseItems = useMemo(
    () => (widgetCount > 0 ? tasksbaseItems : tasksbaseItems.filter((item) => item.key !== 'widgets')),
    [tasksbaseItems, widgetCount],
  )
  const knowledgebaseItems = useMemo(
    () => applyMenuBranding(knowledgebaseMenuItems, branding?.menu.order ?? [], branding?.menu.hidden ?? []),
    [branding],
  )
  const menuLabels = branding?.i18n.labels ?? {}
  const activeMenuItem = [...visibleTasksbaseItems, ...knowledgebaseItems].find(
    (item) => location.pathname === item.path,
  )
  const activeMenuText = activeMenuItem ? menuLabels[activeMenuItem.key] || activeMenuItem.text : null
  const [mobileOpen, setMobileOpenState] = useState(false)
  const [anchorEl, setAnchorEl] = useState<null | HTMLElement>(null)
  const [searchOpen, setSearchOpen] = useState(false)
  const [sidebarOpen, setSidebarOpen] = useState(() => {
    const saved = localStorage.getItem('sidebarOpen')
    return saved === null ? true : JSON.parse(saved)
  })
  const isMobile = useMediaQuery(theme.breakpoints.down('md'))

  // Live notification badge / list updates over Reverb.
  useNotificationRealtime()

  // Store setter for use in MenuSection
  setMobileOpen = setMobileOpenState

  // Global search hotkey (Ctrl/Cmd+K)
  useEffect(() => {
    const handler = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setSearchOpen(true)
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])

  // Save sidebar state to localStorage
  useEffect(() => {
    localStorage.setItem('sidebarOpen', JSON.stringify(sidebarOpen))
  }, [sidebarOpen])

  const [chatOpen, setChatOpen] = useState(() => {
    const saved = localStorage.getItem('chatWidgetOpen')
    return saved === null ? true : saved === 'true'
  })

  // Persist chat panel state across navigation / reloads
  useEffect(() => {
    localStorage.setItem('chatWidgetOpen', String(chatOpen))
  }, [chatOpen])

  const [chatMinimized, setChatMinimized] = useState(() => {
    return localStorage.getItem('chatWidgetMinimized') === 'true'
  })

  useEffect(() => {
    localStorage.setItem('chatWidgetMinimized', String(chatMinimized))
  }, [chatMinimized])

  const handleDrawerToggle = () => {
    setMobileOpenState(!mobileOpen)
  }

  const handleSidebarToggle = () => {
    setSidebarOpen(!sidebarOpen)
  }

  const handleProfileClick = (event: React.MouseEvent<HTMLElement>) => {
    setAnchorEl(event.currentTarget)
  }

  const handleProfileClose = () => {
    setAnchorEl(null)
  }

  const handleLogout = async () => {
    handleProfileClose()
    await logout()
    navigate('/login')
  }

  const drawer = (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      <Toolbar
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          px: [1],
          py: 2,
          borderBottom: 1,
          borderColor: 'rgba(255, 255, 255, 0.1)',
          minHeight: '64px !important',
        }}
      >
        {(isMobile || sidebarOpen) && (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexGrow: 1, minWidth: 0 }}>
            <Box component="img" src={brandLogo} alt={brandName} sx={{ width: 28, height: 28, flexShrink: 0 }} />
            <Typography
              variant="h6"
              component="div"
              sx={{
                fontWeight: 700,
                color: '#ffffff',
                fontSize: '1.05rem',
                letterSpacing: '-0.01em',
                whiteSpace: 'nowrap',
                overflow: 'hidden',
                textOverflow: 'ellipsis',
              }}
            >
              {brandName}
            </Typography>
          </Box>
        )}
        {isMobile && (
          <IconButton onClick={handleDrawerToggle} sx={{ ml: 'auto', color: '#ffffff' }}>
            <CloseIcon />
          </IconButton>
        )}
        {!isMobile && sidebarOpen && (
          <Tooltip title="Свернуть меню" arrow placement="right">
            <IconButton onClick={handleSidebarToggle} sx={{ ml: 'auto', color: 'rgba(255, 255, 255, 0.7)' }}>
              <ChevronLeftIcon />
            </IconButton>
          </Tooltip>
        )}
        {!isMobile && !sidebarOpen && (
          <Tooltip title="Развернуть меню" arrow placement="right">
            <IconButton onClick={handleSidebarToggle} sx={{ ml: 'auto', color: 'rgba(255, 255, 255, 0.7)' }}>
              <ChevronRightIcon />
            </IconButton>
          </Tooltip>
        )}
      </Toolbar>
      {/* Tasksbase Section */}
      <MenuSection
        items={visibleTasksbaseItems}
        labels={menuLabels}
        location={location}
        navigate={navigate}
        isMobile={isMobile}
        sidebarOpen={sidebarOpen}
      />

      {/* Knowledgebase Section */}
      <MenuSection
        items={knowledgebaseItems}
        labels={menuLabels}
        location={location}
        navigate={navigate}
        isMobile={isMobile}
        sidebarOpen={sidebarOpen}
      />

      <Box sx={{ flexGrow: 1 }} />

      {/* Calendar */}
      {(isMobile || sidebarOpen) && (
        <Box sx={{ borderTop: 1, borderColor: 'rgba(255, 255, 255, 0.1)', maxHeight: '42vh', overflow: 'auto' }}>
          <SidebarCalendar />
        </Box>
      )}

      <Divider sx={{ borderColor: 'rgba(255, 255, 255, 0.1)' }} />
      <List sx={{ px: 1, pb: 2 }}>
        <Tooltip title="Выйти" placement="right" disableHoverListener={sidebarOpen || isMobile} arrow>
          <ListItem disablePadding>
            <ListItemButton
              onClick={handleLogout}
              sx={{
                mx: 'auto',
                borderRadius: 10,
                justifyContent: 'center',
                px: sidebarOpen && !isMobile ? 2.5 : 1,
                minHeight: 44,
                color: 'rgba(255, 255, 255, 0.7)',
                width: sidebarOpen && !isMobile ? 'auto' : 40,
                '&:hover': {
                  backgroundColor: 'rgba(255, 86, 48, 0.15)',
                  color: '#FF5630',
                },
              }}
            >
              <ListItemIcon
                sx={{
                  minWidth: 40,
                  justifyContent: 'center',
                  marginRight: 0,
                  color: 'inherit',
                }}
              >
                <LogoutIcon />
              </ListItemIcon>
              <ListItemText
                primary="Выход"
                sx={{
                  display: sidebarOpen && !isMobile ? 'block' : 'none',
                  '& .MuiListItemText-primary': {
                    color: 'inherit',
                    fontWeight: 500,
                  },
                }}
              />
            </ListItemButton>
          </ListItem>
        </Tooltip>
      </List>
    </Box>
  )

  return (
    <Box sx={{ display: 'flex' }}>
      {loading && (
        <Box
          sx={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 9999,
            bgcolor: 'background.default',
          }}
        >
          <CircularProgress />
        </Box>
      )}
      {/* AppBar */}
      <AppBar
        position="fixed"
        sx={{
          width: {
            md: sidebarOpen ? `calc(100% - ${drawerWidth}px)` : `calc(100% - ${collapsedDrawerWidth}px)`,
          },
          ml: {
            md: sidebarOpen ? `${drawerWidth}px` : `${collapsedDrawerWidth}px`,
          },
          bgcolor: 'background.paper',
          color: 'text.primary',
          boxShadow: 1,
          transition: theme.transitions.create(['width', 'margin'], {
            easing: theme.transitions.easing.easeOut,
            duration: theme.transitions.duration.enteringScreen,
          }),
        }}
      >
        <Toolbar>
          <IconButton color="inherit" edge="start" onClick={handleDrawerToggle} sx={{ mr: 2, display: { md: 'none' } }}>
            <MenuIcon />
          </IconButton>
          <Typography variant="h6" noWrap component="div" sx={{ flexGrow: 1 }}>
            {activeMenuText ||
              (location.pathname.startsWith('/profile')
                ? 'Профиль'
                : location.pathname.startsWith('/search')
                  ? 'Поиск'
                  : 'Канбан-доска')}
          </Typography>

          {/* Theme mode */}
          <Tooltip title={themeMode === 'dark' ? 'Светлая тема' : 'Тёмная тема'}>
            <IconButton color="inherit" onClick={toggleTheme} aria-label="Переключить тему">
              {themeMode === 'dark' ? <LightModeIcon /> : <DarkModeIcon />}
            </IconButton>
          </Tooltip>

          {/* Global search */}
          <Tooltip title="Поиск (Ctrl+K)">
            <IconButton color="inherit" onClick={() => setSearchOpen(true)} aria-label="Глобальный поиск">
              <SearchIcon />
            </IconButton>
          </Tooltip>

          {/* Notifications */}
          <NotificationPanel />

          {/* User Menu */}
          <Tooltip title={user?.full_name || user?.email}>
            <IconButton onClick={handleProfileClick} sx={{ p: 0 }}>
              <Avatar
                sx={{
                  width: 32,
                  height: 32,
                  bgcolor: 'primary.main',
                  color: 'white',
                  fontSize: '0.875rem',
                }}
              >
                {user?.initials || <PersonIcon />}
              </Avatar>
            </IconButton>
          </Tooltip>
          <Menu
            anchorEl={anchorEl}
            open={Boolean(anchorEl)}
            onClose={handleProfileClose}
            anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
            transformOrigin={{ vertical: 'top', horizontal: 'right' }}
          >
            <MenuItem
              onClick={() => {
                handleProfileClose()
                navigate('/profile')
              }}
            >
              <ListItemIcon>
                <PersonIcon fontSize="small" />
              </ListItemIcon>
              Профиль
            </MenuItem>
            <MenuItem
              onClick={() => {
                handleProfileClose()
                navigate('/notifications')
              }}
            >
              <ListItemIcon>
                <NotificationsIcon fontSize="small" />
              </ListItemIcon>
              Уведомления
            </MenuItem>
            <MenuItem onClick={handleLogout}>
              <ListItemIcon>
                <LogoutIcon fontSize="small" />
              </ListItemIcon>
              Выход
            </MenuItem>
          </Menu>
        </Toolbar>
      </AppBar>

      {/* Drawer */}
      <Drawer
        variant="permanent"
        sx={{
          display: { xs: 'none', md: 'block' },
          width: sidebarOpen ? drawerWidth : collapsedDrawerWidth,
          flexShrink: 0,
          whiteSpace: 'nowrap',
          boxSizing: 'border-box',
          '& .MuiDrawer-paper': {
            width: sidebarOpen ? drawerWidth : collapsedDrawerWidth,
            boxSizing: 'border-box',
            border: 'none',
            background: theme.palette.background.sidebar,
            color: '#ffffff',
            transition: theme.transitions.create('width', {
              easing: theme.transitions.easing.easeOut,
              duration: theme.transitions.duration.enteringScreen,
            }),
          },
        }}
        open
      >
        {drawer}
      </Drawer>

      {/* Main content */}
      <Box
        component="main"
        sx={{
          flexGrow: 1,
          boxSizing: 'border-box',
          pt: 3,
          pb: 3,
          pl: 3,
          pr: { xs: 3, md: chatOpen && !chatMinimized ? `${chatPanelWidth}px` : 3 },
          width: {
            md: sidebarOpen ? `calc(100% - ${drawerWidth}px)` : `calc(100% - ${collapsedDrawerWidth}px)`,
          },
          mt: 8,
          bgcolor: theme.palette.background.default,
          minHeight: 'calc(100vh - 64px)',
          transition: theme.transitions.create('width', {
            easing: theme.transitions.easing.easeOut,
            duration: theme.transitions.duration.enteringScreen,
          }),
        }}
      >
        <Outlet />
        <BrandFooter />
        <ChatWidget
          open={chatOpen}
          minimized={chatMinimized}
          onOpenChange={setChatOpen}
          onMinimizedChange={setChatMinimized}
        />
        <ToastHost />
        <OfflineBanner />
        <SearchDialog open={searchOpen} onClose={() => setSearchOpen(false)} />
      </Box>
    </Box>
  )
}
