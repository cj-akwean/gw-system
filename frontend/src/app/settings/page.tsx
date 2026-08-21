"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { KeyRound, Link2, Loader2 } from "lucide-react";
import HoldButton from "@/components/kokonutui/hold-button";
import ProfileSetup from "@/components/kokonutui/avatar-picker";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { PageLoader } from "@/components/portal/page-loader";
import { LinkMeterForm } from "@/components/portal/link-meter-form";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { AnimatedOTPInput } from "@/components/smoothui/otp-input";
import { OtpChannelPicker } from "@/components/portal/otp-channel-picker";
import { AUTH_NOTICE_PASSWORD_CHANGED, useAuth } from "@/lib/auth-context";
import { useLogoutRedirect } from "@/lib/use-logout-redirect";
import {
  changePasswordApi,
  checkSmsHealth,
  getLinks,
  sendPasswordChangeOtp,
  unlinkApi,
  type PortalLink,
} from "@/lib/api";

const NO_PHONE_SMS_MESSAGE =
  "Add a phone number in the profile section above to get codes by SMS.";
const NO_PHONE_SMS_PARTS = NO_PHONE_SMS_MESSAGE.split("profile section");

export default function SettingsPage() {
  const router = useRouter();
  const { isAuthenticated, ready, user, updateProfile } = useAuth();
  const { loggingOut, logoutAndRedirect } = useLogoutRedirect();

  const [links, setLinks] = useState<PortalLink[]>([]);
  const [linksLoaded, setLinksLoaded] = useState(false);
  const [linksError, setLinksError] = useState("");
  const [profileSaved, setProfileSaved] = useState(false);
  const [profileError, setProfileError] = useState("");
  const [unlinkError, setUnlinkError] = useState("");
  const [unlinkingId, setUnlinkingId] = useState<number | null>(null);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [otp, setOtp] = useState("");
  const [otpSent, setOtpSent] = useState(false);
  const [sendingOtp, setSendingOtp] = useState(false);
  const [passwordError, setPasswordError] = useState("");
  const [changingPassword, setChangingPassword] = useState(false);
  const [otpChannel, setOtpChannel] = useState<"email" | "sms">("email");
  const [smsAvailable, setSmsAvailable] = useState(false);

  useEffect(() => {
    if (!ready || !isAuthenticated) return;
    getLinks()
      .then(setLinks)
      .catch((err: unknown) => {
        setLinksError(err instanceof Error ? err.message : "Couldn't load your meters.");
      })
      .finally(() => setLinksLoaded(true));

    checkSmsHealth()
      .then((health) => setSmsAvailable(health.available))
      .catch(() => setSmsAvailable(false));
  }, [ready, isAuthenticated]);

  useEffect(() => {
    if (ready && !isAuthenticated && !loggingOut) {
      router.replace("/auth");
    }
  }, [ready, isAuthenticated, loggingOut, router]);

  const handleLogout = () => {
    void logoutAndRedirect("/");
  };

  const handleUnlink = async (linkId: number) => {
    setUnlinkError("");
    setUnlinkingId(linkId);
    try {
      await unlinkApi(linkId);
      setLinks((prev) => prev.filter((link) => link.id !== linkId));
    } catch (err) {
      setUnlinkError(err instanceof Error ? err.message : "Couldn't unlink the meter.");
    } finally {
      setUnlinkingId(null);
    }
  };

  const handleSendOtp = async () => {
    setPasswordError("");
    setOtpSent(false);

    if (newPassword.length < 8) {
      setPasswordError("New password must be at least 8 characters.");
      return;
    }

    if (newPassword !== confirmPassword) {
      setPasswordError("New passwords do not match.");
      return;
    }

    if (!currentPassword) {
      setPasswordError("Enter your current password first.");
      return;
    }

    if (otpChannel === "sms" && !user?.phone) {
      setPasswordError(NO_PHONE_SMS_MESSAGE);
      return;
    }

    setSendingOtp(true);
    try {
      await sendPasswordChangeOtp(otpChannel);
      setOtpSent(true);
    } catch (err) {
      setPasswordError(
        err instanceof Error ? err.message : "Couldn't send the code."
      );
    } finally {
      setSendingOtp(false);
    }
  };

  const handlePasswordChange = async () => {
    setPasswordError("");

    if (newPassword.length < 8) {
      setPasswordError("New password must be at least 8 characters.");
      return;
    }

    if (newPassword !== confirmPassword) {
      setPasswordError("New passwords do not match.");
      return;
    }

    if (!otpSent) {
      setPasswordError("Send a verification code first.");
      return;
    }

    if (!/^\d{6}$/.test(otp)) {
      setPasswordError(
        otpChannel === "sms"
          ? "Enter the 6-digit code from your phone."
          : "Enter the 6-digit code from your email."
      );
      return;
    }

    setChangingPassword(true);
    try {
      await changePasswordApi(currentPassword, newPassword, otp);
      await logoutAndRedirect(
        `/auth?notice=${AUTH_NOTICE_PASSWORD_CHANGED}`
      );
    } catch (err) {
      setPasswordError(
        err instanceof Error ? err.message : "Couldn't change your password."
      );
    } finally {
      setChangingPassword(false);
    }
  };

  if (!ready) {
    return <PageLoader />;
  }

  if (!isAuthenticated) {
    return <PageLoader />;
  }

  return (
    <div className="relative min-h-screen w-full" style={{ background: "var(--bg)" }}>
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

      <div className="relative z-10 mx-auto flex min-h-screen w-full max-w-md flex-col px-6 pb-12 md:max-w-4xl">
        <DashboardHeader user={user} onLogout={handleLogout} />
        <main className="flex-1 space-y-10 py-8">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Settings</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Edit your profile and manage your meters.
            </p>
          </div>

          <section className="space-y-4">
            <h2 className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
              Profile
            </h2>
            <ProfileSetup
              heading="Edit your profile"
              subtitle="Update your avatar, display name and phone number."
              submitLabel="Save"
              initialAvatarId={user?.avatar_id ?? undefined}
              initialName={user?.name ?? ""}
              initialPhone={user?.phone ?? ""}
              withPhone
              onComplete={async ({ name, avatarId, phone }) => {
                try {
                  await updateProfile(name, avatarId, phone);
                  setProfileError("");
                  setProfileSaved(true);
                } catch (err) {
                  setProfileSaved(false);
                  setProfileError(
                    err instanceof Error ? err.message : "Couldn't save your profile."
                  );
                }
              }}
            />
            {profileSaved && (
              <p className="text-center text-sm text-primary" role="status">
                Profile saved.
              </p>
            )}
            {profileError && (
              <p className="text-destructive text-sm text-center" role="alert">
                {profileError}
              </p>
            )}
          </section>

          <section className="space-y-4">
            <h2 className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
              Security
            </h2>

            <div className="rounded-xl border border-border bg-card p-8">
              <div className="flex items-center gap-2">
                <KeyRound aria-hidden className="size-4 text-muted-foreground" />
                <h3 className="text-sm font-semibold">Change password</h3>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                Use at least 8 characters. After your password is changed,
                you&apos;ll be signed out and asked to sign in again with your new
                password.
              </p>

              <form
                className="mt-6 space-y-4"
                onSubmit={(e) => {
                  e.preventDefault();
                  void handlePasswordChange();
                }}
              >
                <label className="block space-y-1.5">
                  <span className="text-xs font-medium text-muted-foreground">
                    Current password
                  </span>
                  <Input
                    aria-label="Current password"
                    autoComplete="current-password"
                    onChange={(e) => setCurrentPassword(e.target.value)}
                    required
                    type="password"
                    value={currentPassword}
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

                {passwordError && (
                  <p className="text-sm text-destructive" role="alert">
                    {passwordError}
                  </p>
                )}

                {smsAvailable && (
                  <div className="space-y-1.5">
                    <OtpChannelPicker
                      ariaLabel="Verification channel"
                      label="Send verification code via"
                      onChange={setOtpChannel}
                      value={otpChannel}
                    />
                    {otpChannel === "sms" && !user?.phone && (
                      <p className="text-xs text-destructive" role="alert">
                        {NO_PHONE_SMS_PARTS[0]}
                        <a href="#phone" className="underline underline-offset-2">
                          profile section
                        </a>
                        {NO_PHONE_SMS_PARTS[1]}
                      </p>
                    )}
                  </div>
                )}

                <Button
                  aria-disabled={sendingOtp}
                  className="h-10 px-6 text-xs"
                  disabled={sendingOtp}
                  onClick={() => void handleSendOtp()}
                  type="button"
                  variant="outline"
                >
                  {sendingOtp ? (
                    <>
                      <Loader2 aria-hidden className="size-3.5 animate-spin" />
                      Sending…
                    </>
                  ) : otpSent ? (
                    "Resend code"
                  ) : (
                    "Send verification code"
                  )}
                </Button>

                {otpSent && (
                  <label className="block space-y-1.5">
                    <span className="text-xs font-medium text-muted-foreground">
                      Verification code
                    </span>
                    <AnimatedOTPInput
                      aria-label="Verification code"
                      onChange={setOtp}
                      value={otp}
                    />
                    <span className="text-xs text-muted-foreground">
                      {otpChannel === "sms"
                        ? "Check your phone — the code expires in 5 minutes."
                        : "Check your email — the code expires in 5 minutes."}
                    </span>
                  </label>
                )}

                <Button
                  aria-disabled={changingPassword}
                  className="h-10 px-6 text-xs"
                  disabled={changingPassword}
                  type="submit"
                >
                  {changingPassword ? (
                    <>
                      <Loader2 aria-hidden className="size-3.5 animate-spin" />
                      Saving…
                    </>
                  ) : (
                    "Update password"
                  )}
                </Button>
              </form>
            </div>
          </section>

          <section className="space-y-4">
            <h2 className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
              My Meters
            </h2>

            {linksError && (
              <p className="text-destructive text-sm" role="alert">
                {linksError}
              </p>
            )}

            {!linksLoaded ? (
              <div className="flex items-center justify-center rounded-xl border border-border bg-card p-8">
                <Loader2 aria-hidden className="h-5 w-5 animate-spin text-muted-foreground" />
              </div>
            ) : links.length === 0 ? (
              <div className="rounded-xl border border-border bg-card p-8 text-center">
                <p className="text-sm text-muted-foreground">
                  No meters linked yet. Link one below to see and pay your bills.
                </p>
              </div>
            ) : (
              <ul className="space-y-3">
                {links.map((link) => (
                  <li
                    key={link.id}
                    className="flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4"
                  >
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <Link2 aria-hidden className="size-4 shrink-0 text-muted-foreground" />
                        <p className="truncate text-sm font-semibold">
                          {link.service_connection.account_number} ·{" "}
                          {link.service_connection.meter_number}
                        </p>
                      </div>
                      <p className="mt-1 truncate text-xs text-muted-foreground">
                        {link.service_connection.registered_name}
                        {link.service_connection.barangay
                          ? ` · ${link.service_connection.barangay.name}`
                          : ""}
                      </p>
                    </div>
                    <HoldButton
                      aria-label={`Unlink ${link.service_connection.account_number}`}
                      className="h-9 shrink-0 rounded-lg px-4 text-xs"
                      disabled={unlinkingId === link.id}
                      holdDuration={1500}
                      label={unlinkingId === link.id ? "Unlinking…" : "Hold to unlink"}
                      holdingLabel="Release to unlink"
                      type="button"
                      variant="red"
                      onClick={() => handleUnlink(link.id)}
                    />
                  </li>
                ))}
              </ul>
            )}

            {unlinkError && (
              <p className="text-destructive text-sm" role="alert">
                {unlinkError}
              </p>
            )}

            <div className="rounded-xl border border-border bg-card p-8">
              <LinkMeterForm
                onLinked={(link) => {
                  setLinks((prev) =>
                    prev.some((existing) => existing.id === link.id)
                      ? prev
                      : [...prev, link]
                  );
                }}
              />
            </div>
          </section>
        </main>
      </div>
    </div>
  );
}
