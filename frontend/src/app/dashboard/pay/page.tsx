"use client";

import { Suspense, useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useAuth } from "@/lib/auth-context";
import { PaymentMethodScreen } from "@/components/portal/payment-method";

export default function PayPage() {
  return (
    <Suspense fallback={null}>
      <PayPageContent />
    </Suspense>
  );
}

function PayPageContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { isAuthenticated, ready } = useAuth();

  const invoiceId = searchParams.get("id") ?? "";
  const returnedFromGcash = searchParams.get("from") === "gcash";

  useEffect(() => {
    if (ready && !isAuthenticated) {
      router.replace("/auth");
    }
  }, [ready, isAuthenticated, router]);

  if (!ready || !isAuthenticated) {
    return null;
  }

  return (
    <PaymentMethodScreen
      invoiceId={invoiceId}
      returnedFromGcash={returnedFromGcash}
    />
  );
}
