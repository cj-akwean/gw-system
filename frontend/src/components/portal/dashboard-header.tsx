"use client";

import Link from "next/link";
import { ProfileDropdown } from "@/components/portal/profile-dropdown";
import type { PortalUser } from "@/lib/api";

interface DashboardHeaderProps {
  user: PortalUser | null;
  onLogout: () => void;
}

export function DashboardHeader({ user, onLogout }: DashboardHeaderProps) {
  return (
    <header className="sticky top-0 z-20 -mx-6 border-b border-border/60 bg-background/70 px-6 py-4 backdrop-blur-sm">
      <div className="flex items-center justify-between gap-3">
        <Link
          href="/"
          className="min-w-0 truncate text-lg font-bold tracking-tight transition-colors hover:text-foreground/80"
        >
          Guinobatan Waterworks<span className="text-amber-500">.</span>
        </Link>
        <ProfileDropdown user={user} onLogout={onLogout} />
      </div>
    </header>
  );
}
