"use client";

import { useState } from "react";
import { LayoutDashboard, LogOut, Moon, Settings, Sun, User } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { PortalUser } from "@/lib/api";
import { AVATAR_RGB, getAvatar } from "@/lib/avatars";
import { useTheme } from "@/lib/theme";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { cn } from "@/lib/utils";

interface ProfileDropdownProps {
  user: PortalUser | null;
  onLogout: () => void;
}

export function ProfileDropdown({ user, onLogout }: ProfileDropdownProps) {
  const pathname = usePathname();
  const { dark, mounted, toggle } = useTheme();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const avatar = user?.avatar_id ? getAvatar(user.avatar_id) : null;
  const rgb = user?.avatar_id ? AVATAR_RGB[user.avatar_id] : null;
  const displayName = user?.name || "My Account";
  const onDashboard = pathname === "/dashboard";

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          aria-label="Open profile menu"
          className="flex cursor-pointer items-center gap-3 rounded-2xl border border-border bg-background/70 p-2.5 pr-3 backdrop-blur-sm transition-colors hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-ring/30"
          type="button"
        >
          <div className="hidden min-w-0 text-left sm:block">
            <div className="truncate text-sm leading-tight font-medium tracking-tight">
              {displayName}
            </div>
            {user?.email && (
              <div className="truncate text-xs leading-tight tracking-tight text-muted-foreground">
                {user.email}
              </div>
            )}
          </div>
          <div className="relative h-10 w-10 shrink-0">
            {rgb && (
              <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 rounded-full"
                style={{ boxShadow: `0 0 0 2px rgba(${rgb}, 0.55)` }}
              />
            )}
            <div className="relative h-full w-full overflow-hidden rounded-full bg-muted">
              {avatar ? (
                <div className="flex h-full w-full items-center justify-center">
                  {avatar.svg}
                </div>
              ) : (
                <div className="flex h-full w-full items-center justify-center">
                  <User className="h-5 w-5 text-muted-foreground" />
                </div>
              )}
            </div>
          </div>
        </button>
      </DropdownMenuTrigger>

      <DropdownMenuContent
        align="end"
        className="w-56 origin-top-right rounded-2xl border-border bg-popover/95 p-2 shadow-xl shadow-zinc-900/5 backdrop-blur-sm"
        sideOffset={4}
      >
        <div className="space-y-1">
          {!onDashboard && (
            <DropdownMenuItem asChild>
              <Link href="/dashboard">
                <LayoutDashboard className="h-4 w-4" />
                Dashboard
              </Link>
            </DropdownMenuItem>
          )}
          <DropdownMenuItem asChild>
            <Link href="/settings">
              <Settings className="h-4 w-4" />
              Settings
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild>
            <button
              data-testid="theme-toggle-item"
              type="button"
              onClick={(e) => toggle(e.currentTarget)}
              disabled={!mounted}
            >
              {dark ? (
                <Sun className="h-4 w-4" />
              ) : (
                <Moon className="h-4 w-4" />
              )}
              {dark ? "Light mode" : "Dark mode"}
            </button>
          </DropdownMenuItem>
        </div>

        <DropdownMenuSeparator className="my-2 bg-gradient-to-r from-transparent via-border to-transparent" />

        <DropdownMenuItem
          asChild
          className={cn(
            "cursor-pointer bg-destructive/10 text-destructive focus:bg-destructive/20 focus:text-destructive",
            "dark:bg-destructive/20"
          )}
        >
          <button
            data-testid="profile-sign-out"
            type="button"
            onClick={() => setConfirmOpen(true)}
          >
            <LogOut className="h-4 w-4 text-destructive" />
            Sign Out
          </button>
        </DropdownMenuItem>
      </DropdownMenuContent>

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Sign out?</AlertDialogTitle>
            <AlertDialogDescription>
              You&apos;ll need to sign in again to view and pay your bills.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              data-testid="confirm-sign-out"
              onClick={() => {
                setConfirmOpen(false);
                onLogout();
              }}
            >
              Sign out
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </DropdownMenu>
  );
}
