"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth-context";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { PageLoader } from "@/components/portal/page-loader";
import { BillsList } from "@/components/portal/bills-list";
import { LinkMeterPrompt } from "@/components/portal/link-meter-prompt";

function greeting(date: Date): string {
  const h = date.getHours();
  if (h < 12) return "Good morning";
  if (h < 18) return "Good afternoon";
  return "Good evening";
}

function todayLabel(date: Date): string {
  return date.toLocaleDateString("en-PH", {
    weekday: "long",
    month: "long",
    day: "numeric",
  });
}

export default function DashboardPage() {
  const router = useRouter();
  const { isAuthenticated, ready, user, logout } = useAuth();
  const [loggingOut, setLoggingOut] = useState(false);

  useEffect(() => {
    if (ready && !isAuthenticated && !loggingOut) {
      router.replace("/auth");
    }
  }, [ready, isAuthenticated, loggingOut, router]);

  const handleLogout = async () => {
    setLoggingOut(true);
    await logout();
    router.push("/");
  };

  if (!ready || !isAuthenticated) {
    return <PageLoader />;
  }

  const now = new Date();

  return (
    <div
      className="relative min-h-screen w-full"
      style={{ background: "var(--bg)" }}
    >
      <div
        className="pointer-events-none fixed inset-0"
        style={{ background: "var(--glow) no-repeat", filter: "blur(var(--glow-blur))" }}
      />
      <div
        className="pointer-events-none fixed inset-0"
        style={{
          backgroundImage:
            "radial-gradient(circle at 1px 1px, var(--dot) 1px, transparent 0)",
          backgroundSize: "20px 20px",
        }}
      />

      <div className="relative z-10 mx-auto flex min-h-screen w-full max-w-md flex-col px-6 pb-12 md:max-w-4xl lg:max-w-5xl">
        <DashboardHeader user={user} onLogout={handleLogout} />
        <main className="flex-1 space-y-8 pt-6 md:pt-8">
          <div>
            <p className="text-sm text-muted-foreground">{todayLabel(now)}</p>
            <h1 className="mt-1 text-2xl font-bold tracking-tight">
              {greeting(now)}
              {user?.name ? `, ${user.name}` : ""}!
            </h1>
          </div>
          <LinkMeterPrompt />
          <BillsList />
        </main>
      </div>
    </div>
  );
}