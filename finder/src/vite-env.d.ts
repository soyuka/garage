/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_CASDOOR_ISSUER?: string;
  readonly VITE_CASDOOR_CLIENT_ID?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
