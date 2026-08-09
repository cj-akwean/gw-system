"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Link2, Loader2 } from "lucide-react";
import HoldButton from "@/components/kokonutui/hold-button";
import ProfileSetup from "@/components/kokonutui/avatar-picker";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { LinkMeterForm } from "@/components/portal/link-meter-form";
import { useAuth } from "@/lib/auth-context";
import { getLinks, unlinkApi, type PortalLink } from "@/lib/api";

export default function SettingsPage() {
  const router = useRouter();
  const { isAuthenticated, ready, user, logout, updateProfile } = useAuth();

  const [links, setLinks] = useState<PortalLink[]>([]);
  const [linksLoaded, setLinksLoaded] = useState(false);
  const [linksError, setLinksError] = useState("");
  const [profileSaved, setProfileSaved] = useState(false);
  const [profileError, setProfileError] = useState("");
  const [unlinkError, setUnlinkError] = useState("");
  const [unlinkingId, setUnlinkingId] = useState<number | null>(null);
  const [loggingOut, setLoggingOut] = useState(false);

  useEffect(() => {
    if (!ready || !isAuthenticated) return;
    getLinks()
      .then(setLinks)
      .catch((err: unknown) => {
        setLinksError(err instanceof Error ? err.message : "Couldn't load your meters.");
      })
      .finally(() => setLinksLoaded(true));
  }, [ready, isAuthenticated]);

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

  if (!ready) {
    return null;
  }

  if (!isAuthenticated) {
    return null;
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
              subtitle="Update your avatar and display name."
              initialAvatarId={user?.avatar_id ?? undefined}
              initialUsername={user?.name ?? ""}
              onComplete={async ({ username, avatarId }) => {
                try {
                  await updateProfile(username, avatarId);
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
