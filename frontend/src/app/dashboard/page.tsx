"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth-context";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { BillsList } from "@/components/portal/bills-list";

export default function DashboardPage() {
  const router = useRouter();
  const { isAuthenticated, ready, user, logout } = useAuth();

  useEffect(() => {
    if (ready && !isAuthenticated) {
      router.replace("/auth");
    }
  }, [ready, isAuthenticated, router]);

  if (!ready) {
    return null;
  }

  if (!isAuthenticated) {
    return null;
  }

  return (
    <div
      className="relative min-h-screen w-full"
      style={{ background: "var(--bg)" }}
    >
      <div
        className="pointer-events-none fixed inset-0"
        style={{ background: "var(--glow) no-repeat", filter: "blur(80px)" }}
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
        <DashboardHeader
          userName={user?.name}
          userEmail={user?.email}
          onLogout={() => logout()}
        />
        <main className="flex-1">
          <BillsList />
        </main>
      </div>
    </div>
  );
}