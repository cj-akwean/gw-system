import { toast as sonnerToast } from "sonner";

/**
 * Thin wrapper around sonner's `toast` so call sites don't import sonner
 * directly (keeps the Toaster provider the single source of truth and makes
 * toasts easy to mock in tests). Exposes the subset the app uses.
 */
export const toast = {
  success: (message: string) => sonnerToast.success(message),
  error: (message: string) => sonnerToast.error(message),
  info: (message: string) => sonnerToast.info(message),
  warning: (message: string) => sonnerToast.warning(message),
};
