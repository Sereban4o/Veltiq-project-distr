import { useState, type ReactElement } from 'react'
import { useLocation } from 'react-router-dom'
import { Box, Divider, List, ListItemButton, ListItemText, Typography } from '@mui/material'
import {
  Dashboard as DashboardIcon,
  Business as CompanyIcon,
  People as ContactIcon,
  TrendingUp as DealIcon,
  TaskAlt as ActivityIcon,
} from '@mui/icons-material'
import DashboardTab from '../components/DashboardTab'
import CompaniesTab from '../components/CompaniesTab'
import ContactsTab from '../components/ContactsTab'
import DealsTab from '../components/DealsTab'
import ActivitiesTab from '../components/ActivitiesTab'

type Section = 'dashboard' | 'companies' | 'contacts' | 'deals' | 'activities'

const SECTIONS: { value: Section; label: string; icon: ReactElement }[] = [
  { value: 'dashboard', label: 'Обзор', icon: <DashboardIcon /> },
  { value: 'deals', label: 'Сделки', icon: <DealIcon /> },
  { value: 'companies', label: 'Компании', icon: <CompanyIcon /> },
  { value: 'contacts', label: 'Контакты', icon: <ContactIcon /> },
  { value: 'activities', label: 'Задачи', icon: <ActivityIcon /> },
]

export default function CrmPage() {
  const location = useLocation()
  const [section, setSection] = useState<Section>(() => {
    const initial = (location.state as { section?: Section } | null)?.section
    return initial && SECTIONS.some((s) => s.value === initial) ? initial : 'dashboard'
  })

  return (
    <Box sx={{ display: 'flex', gap: 2, height: 'calc(100vh - 120px)' }}>
      <Box sx={{ width: 210, flexShrink: 0, borderRight: '1px solid', borderColor: 'divider', pr: 1 }}>
        <Typography sx={{ fontSize: '1.15rem', fontWeight: 700, mb: 1, px: 1 }}>CRM</Typography>
        <Divider sx={{ mb: 1 }} />
        <List dense>
          {SECTIONS.map((item) => (
            <ListItemButton
              key={item.value}
              selected={section === item.value}
              onClick={() => setSection(item.value)}
              sx={{ borderRadius: 2, mb: 0.5 }}
            >
              <Box sx={{ mr: 1.5, display: 'flex', color: 'text.secondary' }}>{item.icon}</Box>
              <ListItemText primary={item.label} />
            </ListItemButton>
          ))}
        </List>
      </Box>

      <Box sx={{ flex: 1, minWidth: 0, overflow: 'auto' }}>
        {section === 'dashboard' && <DashboardTab />}
        {section === 'deals' && <DealsTab />}
        {section === 'companies' && <CompaniesTab />}
        {section === 'contacts' && <ContactsTab />}
        {section === 'activities' && <ActivitiesTab />}
      </Box>
    </Box>
  )
}
