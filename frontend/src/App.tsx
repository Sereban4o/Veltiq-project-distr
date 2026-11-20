import { lazy, Suspense, useEffect, useMemo, useState } from 'react'
import { createBrowserRouter, Navigate, RouterProvider, type RouteObject } from 'react-router-dom'
import { Box, CircularProgress } from '@mui/material'

const LoginPage = lazy(() => import('./modules/auth/pages/LoginPage'))
const ProfilePage = lazy(() => import('./modules/auth/pages/ProfilePage'))
const DownloadsPage = lazy(() => import('./modules/releases/pages/DownloadsPage'))
const NotificationsPage = lazy(() => import('./modules/notifications/pages/NotificationsPage'))
const MailPage = lazy(() => import('./modules/mail/pages/MailPage'))
const FileManagerPage = lazy(() => import('./modules/storage/pages/FileManagerPage'))
const PublicSharePage = lazy(() => import('./modules/storage/pages/PublicSharePage'))
const CrmPage = lazy(() => import('./modules/crm/pages/CrmPage'))
const CalendarPage = lazy(() => import('./modules/calendar/pages/CalendarPage'))
const KanbanBoardPage = lazy(() => import('./modules/tasksbase/boards/pages/KanbanBoardPage'))
const SprintPage = lazy(() => import('./modules/tasksbase/sprints/pages/SprintPage'))
const IssueDetailPage = lazy(() => import('./modules/tasksbase/issues/pages/IssueDetailPage'))
const TimelinePage = lazy(() => import('./modules/tasksbase/projects/pages/TimelinePage'))
const TaskAutomationsPage = lazy(() => import('./modules/tasksbase/projects/pages/TaskAutomationsPage'))
const TimesheetPage = lazy(() => import('./modules/tasksbase/projects/pages/TimesheetPage'))
const SpacePage = lazy(() => import('./modules/knowledgebase/spaces/pages/SpacePage'))
const PageEditorPage = lazy(() => import('./modules/knowledgebase/pages/pages/PageEditorPage'))
const PageViewPage = lazy(() => import('./modules/knowledgebase/pages/pages/PageViewPage'))
const PublicPageView = lazy(() => import('./modules/knowledgebase/pages/PublicPageView'))
const SearchPage = lazy(() => import('./modules/search/pages/SearchPage'))
const AssistantPage = lazy(() => import('./modules/ai/pages/AssistantPage'))
const WidgetsPage = lazy(() => import('./modules/widgets/pages/WidgetsPage'))
const AppLayout = lazy(() => import('./modules/shared/components/AppLayout'))
const RouteError = lazy(() => import('./modules/shared/components/RouteError'))

function LoadingFallback() {
  return (
    <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
      <CircularProgress />
    </Box>
  )
}

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    const token = localStorage.getItem('auth_token')
    setIsAuthenticated(!!token)
    setIsLoading(false)
  }, [])

  if (isLoading) {
    return <LoadingFallback />
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  return <>{children}</>
}

export function createAppRouter(router: RouteObject[] = []) {
  const routes: RouteObject[] = [
    { path: '/login', element: <LoginPage />, errorElement: <RouteError /> },
    { path: '/s/:token', element: <PublicSharePage />, errorElement: <RouteError /> },
    { path: '/share/page/:token', element: <PublicPageView />, errorElement: <RouteError /> },
    {
      path: '/',
      element: (
        <ProtectedRoute>
          <AppLayout />
        </ProtectedRoute>
      ),
      errorElement: <RouteError />,
      children: [
        { index: true, element: <KanbanBoardPage /> },
        { path: 'board', element: <KanbanBoardPage /> },
        { path: 'sprints', element: <SprintPage /> },
        { path: 'timeline', element: <TimelinePage /> },
        { path: 'automations', element: <TaskAutomationsPage /> },
        { path: 'timesheet', element: <TimesheetPage /> },
        { path: 'issues/:issueId', element: <IssueDetailPage /> },
        { path: 'knowledgebase', element: <SpacePage /> },
        { path: 'knowledgebase/:spaceKey', element: <SpacePage /> },
        { path: 'knowledgebase/:spaceKey/pages/new', element: <PageEditorPage /> },
        { path: 'knowledgebase/:spaceKey/pages/:pageId', element: <PageViewPage /> },
        { path: 'knowledgebase/:spaceKey/pages/:pageId/edit', element: <PageEditorPage /> },
        { path: 'profile', element: <ProfilePage /> },
        { path: 'notifications', element: <NotificationsPage /> },
        { path: 'downloads', element: <DownloadsPage /> },
        { path: 'mail', element: <MailPage /> },
        { path: 'files', element: <FileManagerPage /> },
        { path: 'crm', element: <CrmPage /> },
        { path: 'calendar', element: <CalendarPage /> },
        { path: 'search', element: <SearchPage /> },
        { path: 'assistant', element: <AssistantPage /> },
        { path: 'widgets', element: <WidgetsPage /> },
      ],
    },
    { path: '*', element: <Navigate to="/" replace /> },
    ...router,
  ]

  return routes
}

export default function App() {
  const appRouter = useMemo(() => createBrowserRouter(createAppRouter(), { future: { v7_startTransition: true } }), [])

  return (
    <Suspense fallback={<LoadingFallback />}>
      <RouterProvider router={appRouter} />
    </Suspense>
  )
}
