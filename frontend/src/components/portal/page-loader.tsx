import { Loader2 } from "lucide-react";

export function PageLoader() {
  return (
    <div
      className="flex min-h-screen w-full items-center justify-center"
      style={{ background: "var(--bg)" }}
      role="status"
      aria-label="Loading"
    >
      <Loader2 aria-hidden className="size-6 animate-spin text-primary" />
    </div>
  );
}
