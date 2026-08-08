"use client";

import { LogOutIcon } from "lucide-react";

interface DashboardHeaderProps {
  userName?: string | null;
  userEmail?: string;
  onLogout: () => void;
}

export function DashboardHeader({
  userName,
  userEmail,
  onLogout,
}: DashboardHeaderProps) {
  return (
    <header className="sticky top-0 z-20 -mx-6 border-b border-border/60 bg-background/70 px-6 py-4 backdrop-blur-sm">
      <div className="flex items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-lg font-bold tracking-tight">
            Guinobatan Waterworks
          </p>
          <p className="truncate text-xs text-muted-foreground">
            {userName ? `${userName} · ` : ""}
            {userEmail ?? ""}
          </p>
        </div>
        <button
          type="button"
          onClick={onLogout}
          data-testid="sign-out"
          className="inline-flex h-9 shrink-0 items-center gap-1 rounded-md border border-border bg-transparent px-4 text-xs font-semibold tracking-widest uppercase transition-colors hover:bg-muted hover:text-foreground"
        >
          <LogOutIcon data-icon="inline-start" className="size-3.5" />
          Sign Out
        </button>
      </div>
    </header>
  );
}