export type BrandingMode = 'light' | 'dark' | 'system'

export interface Branding {
  brand: string
  appName: string
  logo: string
  favicon: string
  theme: {
    palette: {
      primary: string
      secondary: string
      mode: BrandingMode
      modes: string[]
    }
    typography: {
      fontFamily: string
      scale: number
    }
    shape: {
      borderRadius: number
    }
    layout: string
  }
  menu: {
    order: string[]
    hidden: string[]
  }
  i18n: {
    appTitle: string
    labels?: Record<string, string>
  }
  customCss: string
}
