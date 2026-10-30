/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_CASDOOR_ISSUER?: string;
  readonly VITE_CASDOOR_CLIENT_ID?: string;
  readonly VITE_MERCURE_URL?: string;
  readonly VITE_API_BASE?: string;
  readonly VITE_GARAGE_API_BASE?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
