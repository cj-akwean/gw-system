"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Check } from "lucide-react";
import ProfileSetup from "@/components/kokonutui/avatar-picker";
import { OnboardingSteps, type OnboardingStep } from "@/components/onboarding-06";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { LinkMeterForm } from "@/components/portal/link-meter-form";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth-context";
import { useLogoutRedirect } from "@/lib/use-logout-redirect";
import { getLinks, type PortalLink } from "@/lib/api";

const STEP_PROFILE = 1;
const STEP_LINK = 2;
const STEP_DONE = 3;

function buildSteps(step: number, avatarDone: boolean, linkedDone: boolean): OnboardingStep[] {
  return [
    {
      id: 1,
      type: avatarDone || step > STEP_PROFILE ? "done" : step === STEP_PROFILE ? "in progress" : "open",
      title: "Create your profile",
      description: "Pick an avatar, a display name and an optional phone number",
    },
    {
      id: 2,
      type: linkedDone || step > STEP_LINK ? "done" : step === STEP_LINK ? "in progress" : "open",
      title: "Link your meter",
      description: "Connect your account and meter number",
    },
    {
      id: 3,
      type: step === STEP_DONE ? "in progress" : "open",
      title: "You're all set",
      description: "Start paying bills from your dashboard",
    },
  ];
}

function AllSetStep({
  userName,
  skippedLink,
  onDone,
}: {
  userName: string | null | undefined;
  skippedLink: boolean;
  onDone: () => void;
}) {
  return (
    <div className="space-y-6 text-center">
      <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-primary/10">
        <Check aria-hidden className="h-10 w-10 text-primary" />
      </div>
      <div className="space-y-1">
        <h2 className="font-semibold text-xl tracking-tight">
          You&apos;re all set{userName ? `, ${userName}` : ""}!
        </h2>
        <p className="text-muted-foreground text-sm">
          {skippedLink
            ? "Your profile is ready. Link your meter from your dashboard anytime to see your bills."
            : "Your meter is linked — head to your dashboard to see and pay your bills."}
        </p>
      </div>
      <Button className="h-10 w-full text-sm" onClick={onDone} type="button">
        Go to My Dashboard
      </Button>
    </div>
  );
}

export default function OnboardingPage() {
  const router = useRouter();
  const { isAuthenticated, ready, user, updateProfile } = useAuth();
  const { loggingOut, logoutAndRedirect } = useLogoutRedirect();

  const [step, setStep] = useState(STEP_PROFILE);
  const [links, setLinks] = useState<PortalLink[]>([]);
  const [linksLoaded, setLinksLoaded] = useState(false);
  const [profileError, setProfileError] = useState("");
  const initialStepSet = useRef(false);

  useEffect(() => {
    if (!ready || !isAuthenticated) return;
    if (initialStepSet.current) return;

    getLinks()
      .then((fetched) => {
        setLinks(fetched);
        const linkedDone = fetched.length > 0;
        const avatarDone = !!user?.avatar_id;
        setStep(avatarDone ? (linkedDone ? STEP_DONE : STEP_LINK) : STEP_PROFILE);
      })
      .catch(() => {
        setStep(user?.avatar_id ? STEP_LINK : STEP_PROFILE);
      })
      .finally(() => {
        setLinksLoaded(true);
        initialStepSet.current = true;
      });
  }, [ready, isAuthenticated, user?.avatar_id]);

  useEffect(() => {
    if (ready && !isAuthenticated && !loggingOut) {
      router.replace("/auth");
    }
  }, [ready, isAuthenticated, loggingOut, router]);

  const handleLogout = () => {
    void logoutAndRedirect("/");
  };

  if (!ready || !isAuthenticated) {
    return null;
  }

  const avatarDone = !!user?.avatar_id;
  const linkedDone = links.length > 0;
  const skippedLink = linksLoaded && linkedDone === false && step > STEP_LINK;

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

      <div className="relative z-10 mx-auto flex min-h-screen w-full max-w-md flex-col px-6 pb-12 md:max-w-2xl lg:max-w-4xl">
        <DashboardHeader user={user} onLogout={handleLogout} />

        <main className="flex flex-1 items-center py-10">
          <div className="grid w-full gap-10 lg:grid-cols-[260px_1fr]">
            <div className="hidden lg:block">
              <OnboardingSteps
                steps={buildSteps(step, avatarDone, linkedDone)}
                title="Getting started"
              />
            </div>

            <div className="mx-auto w-full max-w-[400px]">
              <div className="mb-8 lg:hidden">
                <OnboardingSteps
                  steps={buildSteps(step, avatarDone, linkedDone)}
                  title="Getting started"
                />
              </div>

              {step === STEP_PROFILE && (
                <ProfileSetup
                  initialPhone={user?.phone ?? ""}
                  onComplete={async ({ username, avatarId, phone }) => {
                    try {
                      await updateProfile(username, avatarId, phone);
                      setProfileError("");
                      setStep(STEP_LINK);
                    } catch (err) {
                      setProfileError(
                        err instanceof Error ? err.message : "Couldn't save your profile."
                      );
                    }
                  }}
                  withPhone
                />
              )}

              {step === STEP_PROFILE && profileError && (
                <p className="mt-4 text-destructive text-sm text-center" role="alert">
                  {profileError}
                </p>
              )}

              {step === STEP_LINK && (
                <div className="rounded-xl border border-border bg-card p-8">
                  <LinkMeterForm
                    onLinked={(link) => {
                      setLinks((prev) => [...prev, link]);
                      setStep(STEP_DONE);
                    }}
                    onSkip={() => setStep(STEP_DONE)}
                  />
                </div>
              )}

              {step === STEP_DONE && (
                <div className="rounded-xl border border-border bg-card p-8">
                  <AllSetStep
                    userName={user?.name}
                    skippedLink={skippedLink}
                    onDone={() => router.push("/dashboard")}
                  />
                </div>
              )}
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
