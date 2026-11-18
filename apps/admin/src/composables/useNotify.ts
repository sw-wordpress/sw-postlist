import { useQuasar } from 'quasar';

/** Short confirmation and error toasts (Quasar Notify). */
export function useNotify(): {
  success: (message: string) => void;
  failure: (message: string) => void;
} {
  const $q = useQuasar();
  return {
    success: (message) => $q.notify({ type: 'positive', message, timeout: 2500 }),
    failure: (message) => $q.notify({ type: 'negative', message, timeout: 6000 }),
  };
}
