import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react';

export interface ToastMessage {
  text: string;
  link?: { href: string; label: string };
  action?: { label: string; onClick: () => void };
}

type Show = (message: string | ToastMessage) => void;

const ToastContext = createContext<Show>(() => undefined);

export const useToast = () => useContext(ToastContext);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [message, setMessage] = useState<ToastMessage | null>(null);
  const timer = useRef<number | undefined>(undefined);
  const show = useCallback<Show>((m) => {
    const next = typeof m === 'string' ? { text: m } : m;
    setMessage(next);
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setMessage(null), next.action ? 5000 : next.link ? 7000 : 2600);
  }, []);
  return (
    <ToastContext.Provider value={show}>
      {children}
      <div className="toast" role="status" aria-live="polite" data-open={message !== null} data-link={message?.link !== undefined || message?.action !== undefined}>
        {message?.text}
        {message?.link && (
          <a href={message.link.href} target="_blank" rel="noopener">
            {message.link.label}
          </a>
        )}
        {message?.action && (
          <button
            type="button"
            onClick={() => {
              message.action?.onClick();
              window.clearTimeout(timer.current);
              setMessage(null);
            }}
          >
            {message.action.label}
          </button>
        )}
      </div>
    </ToastContext.Provider>
  );
}
