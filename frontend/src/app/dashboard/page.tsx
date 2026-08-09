"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth-context";
import {
  MiniCalendar,
  MiniCalendarDay,
  MiniCalendarDays,
  MiniCalendarNavigation,
} from "@/components/kibo-ui/mini-calendar";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { PageLoader } from "@/components/portal/page-loader";
import { BillsList } from "@/components/portal/bills-list";
import { LinkMeterPrompt } from "@/components/portal/link-meter-prompt";

export default function DashboardPage() {
  const router = useRouter();
  const { isAuthenticated, ready, user, logout } = useAuth();
  const [loggingOut, setLoggingOut] = useState(false);
  const [calendarDays, setCalendarDays] = useState(7);

  useEffect(() => {
    if (typeof window.matchMedia !== "function") return;
    const mq = window.matchMedia("(max-width: 639px)");
    const apply = () => setCalendarDays(mq.matches ? 6 : 7);
    apply();
    mq.addEventListener("change", apply);
    return () => mq.removeEventListener("change", apply);
  }, []);

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
        <div className="pt-6 md:pt-8">
          <MiniCalendar
            defaultValue={new Date()}
            days={calendarDays}
            className="w-full justify-center gap-1 rounded-xl border-border bg-card p-1.5"
          >
            <MiniCalendarNavigation direction="prev" className="size-8" />
            <MiniCalendarDays className="gap-0.5">
              {(date) => (
                <MiniCalendarDay
                  date={date}
                  key={date.toISOString()}
                  className="min-w-10 p-1.5 sm:min-w-12"
                />
              )}
            </MiniCalendarDays>
            <MiniCalendarNavigation direction="next" className="size-8" />
          </MiniCalendar>
        </div>
        <main className="flex-1 space-y-8 pt-6 md:pt-8">
          <LinkMeterPrompt />
          <BillsList />
        </main>
      </div>
    </div>
  );
}