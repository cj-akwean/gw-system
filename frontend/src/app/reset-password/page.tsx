"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { AnimatedOTPInput } from "@/components/smoothui/otp-input";
import { resetPasswordApi } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { PageLoader } from "@/components/portal/page-loader";

export default function ResetPasswordPage() {
  const router = useRouter();
  const { isAuthenticated, ready } = useAuth();

  const [email, setEmail] = useState("");
  const [otp, setOtp] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [error, setError] = useState("");
  const [done, setDone] = useState(false);
  const [resetting, setResetting] = useState(false);

  useEffect(() => {
    if (ready && isAuthenticated) {
      router.replace("/dashboard");
    }
  }, [ready, isAuthenticated, router]);

  if (!ready) {
    return <PageLoader />;
  }

  if (isAuthenticated) {
    return <PageLoader />;
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");

    if (newPassword.length < 8) {
      setError("New password must be at least 8 characters.");
      return;
    }

    if (newPassword !== confirmPassword) {
      setError("New passwords do not match.");
      return;
    }

    setResetting(true);
    try {
      await resetPasswordApi(email, otp, newPassword);
      setDone(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Couldn't reset your password.");
    } finally {
      setResetting(false);
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
          <h1 className="text-2xl font-bold tracking-wide">Reset your password</h1>
          <p className="mt-2 text-base text-muted-foreground">
            Enter the 6-digit code we emailed you and choose a new password.
          </p>

          {done ? (
            <div className="mt-6 space-y-4">
              <p className="text-sm text-primary" role="status">
                Password reset. You can now sign in.
              </p>
              <Button asChild className="h-10 w-full px-6 text-xs">
                <Link href="/auth">Go to sign in</Link>
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

              <label className="block space-y-1.5">
                <span className="text-xs font-medium text-muted-foreground">
                  Verification code
                </span>
                <AnimatedOTPInput
                  aria-label="Verification code"
                  onChange={setOtp}
                  value={otp}
                />
              </label>

              <label className="block space-y-1.5">
                <span className="text-xs font-medium text-muted-foreground">
                  New password
                </span>
                <Input
                  aria-label="New password"
                  autoComplete="new-password"
                  onChange={(e) => setNewPassword(e.target.value)}
                  required
                  type="password"
                  value={newPassword}
                />
              </label>

              <label className="block space-y-1.5">
                <span className="text-xs font-medium text-muted-foreground">
                  Confirm new password
                </span>
                <Input
                  aria-label="Confirm new password"
                  autoComplete="new-password"
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  required
                  type="password"
                  value={confirmPassword}
                />
              </label>

              {error && (
                <p className="text-sm text-destructive" role="alert">
                  {error}
                </p>
              )}

              <Button
                aria-disabled={resetting}
                className="h-10 w-full px-6 text-xs"
                disabled={resetting}
                type="submit"
              >
                {resetting ? (
                  <>
                    <Loader2 aria-hidden className="size-3.5 animate-spin" />
                    Resetting…
                  </>
                ) : (
                  "Reset password"
                )}
              </Button>
            </form>
          )}

          <p className="mt-6 text-center text-sm text-muted-foreground">
            Didn't get a code?{" "}
            <Link href="/forgot-password" className="text-primary underline underline-offset-4">
              Request a new one
            </Link>
          </p>
        </div>
      </div>
    </div>
  );
}
