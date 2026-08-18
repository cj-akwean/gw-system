"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { sendPasswordResetOtp, checkSmsHealth } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { PageLoader } from "@/components/portal/page-loader";

export default function ForgotPasswordPage() {
  const router = useRouter();
  const { isAuthenticated, ready } = useAuth();

  const [email, setEmail] = useState("");
  const [error, setError] = useState("");
  const [sent, setSent] = useState(false);
  const [sending, setSending] = useState(false);
  const [smsAvailable, setSmsAvailable] = useState(false);
  const [useSms, setUseSms] = useState(false);

  useEffect(() => {
    if (ready && isAuthenticated) {
      router.replace("/dashboard");
    }
  }, [ready, isAuthenticated, router]);

  useEffect(() => {
    checkSmsHealth()
      .then((health) => setSmsAvailable(health.available))
      .catch(() => setSmsAvailable(false));
  }, []);

  if (!ready) {
    return <PageLoader />;
  }

  if (isAuthenticated) {
    return <PageLoader />;
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setSending(true);
    try {
      await sendPasswordResetOtp(email, useSms ? "sms" : "email");
      setSent(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Couldn't send the code.");
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="flex min-h-screen w-full items-center justify-center p-6"
      style={{ background: "var(--bg)" }}
    >
      <div
        className="pointer-events-none fixed inset-0"
        style={{ background: "var(--glow) no-repeat", filter: "blur(var(--glow-blur))" }}
      />
      <div className="relative z-10 w-full max-w-md">
        <div className="rounded-2xl border border-border bg-card p-8">
          <h1 className="text-2xl font-bold tracking-wide">Forgot your password?</h1>
          <p className="mt-2 text-base text-muted-foreground">
            Enter your email and we'll send you a 6-digit code to reset your password.
          </p>

          {sent ? (
            <div className="mt-6 space-y-4">
              <p className="text-sm text-primary" role="status">
                If an account exists for that email, a verification code is on its way.
              </p>
              <p className="text-sm text-muted-foreground">
                Enter the code on the reset page — it expires in 15 minutes.
              </p>
              <Button asChild className="h-10 w-full px-6 text-xs">
                <Link href="/reset-password">Enter the code</Link>
              </Button>
            </div>
          ) : (
            <form className="mt-6 space-y-4" onSubmit={handleSubmit}>
              <label className="block space-y-1.5">
                <span className="text-xs font-medium text-muted-foreground">Email</span>
                <Input
                  aria-label="Email"
                  autoComplete="email"
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  type="email"
                  value={email}
                />
              </label>

              {smsAvailable && (
                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                  <input
                    aria-label="Send by SMS instead"
                    checked={useSms}
                    onChange={(e) => setUseSms(e.target.checked)}
                    type="checkbox"
                  />
                  Send the code by SMS instead
                  <span className="text-xs text-muted-foreground/70">
                    (only if your account has a phone number)
                  </span>
                </label>
              )}

              {error && (
                <p className="text-sm text-destructive" role="alert">
                  {error}
                </p>
              )}

              <Button
                aria-disabled={sending}
                className="h-10 w-full px-6 text-xs"
                disabled={sending}
                type="submit"
              >
                {sending ? (
                  <>
                    <Loader2 aria-hidden className="size-3.5 animate-spin" />
                    Sending…
                  </>
                ) : (
                  "Send code"
                )}
              </Button>
            </form>
          )}

          <p className="mt-6 text-center text-sm text-muted-foreground">
            Remembered it?{" "}
            <Link href="/auth" className="text-primary underline underline-offset-4">
              Back to sign in
            </Link>
          </p>
        </div>
      </div>
    </div>
  );
}
