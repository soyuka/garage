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
  // The text stays during the exit fade instead of vanishing under it.
  const last = useRef<ToastMessage | null>(null);
  if (message) last.current = message;
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
        {message ? message.text : <span aria-hidden="true">{last.current?.text}</span>}
        {(message ?? last.current)?.link && (
          <a href={(message ?? last.current)!.link!.href} target="_blank" rel="noopener" tabIndex={message ? undefined : -1}>
            {(message ?? last.current)!.link!.label}
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
