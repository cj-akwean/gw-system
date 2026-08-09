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
    <header className="sticky top-0 z-20 pt-3">
      <div className="flex items-center justify-between gap-3 rounded-2xl border border-border/70 bg-background/75 px-4 py-3 shadow-lg shadow-black/5 backdrop-blur-md">
        <Link
          href="/"
          className="min-w-0 truncate text-base font-bold tracking-tight transition-colors hover:text-foreground/80 md:text-lg"
        >
          Guinobatan Waterworks<span className="text-amber-500">.</span>
        </Link>
        <ProfileDropdown user={user} onLogout={onLogout} />
      </div>
    </header>
  );
}
