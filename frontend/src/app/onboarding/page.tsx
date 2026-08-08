"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Check, Loader2, Search } from "lucide-react";
import ProfileSetup from "@/components/kokonutui/avatar-picker";
import { OnboardingSteps, type OnboardingStep } from "@/components/onboarding-06";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useAuth } from "@/lib/auth-context";
import { ApiError, createLink, getLinks, type PortalLink } from "@/lib/api";

const STEP_PROFILE = 1;
const STEP_LINK = 2;
const STEP_DONE = 3;

function buildSteps(step: number, avatarDone: boolean, linkedDone: boolean): OnboardingStep[] {
  return [
    {
      id: 1,
      type: avatarDone || step > STEP_PROFILE ? "done" : step === STEP_PROFILE ? "in progress" : "open",
      title: "Create your profile",
      description: "Pick an avatar and a display name",
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

function LinkMeterStep({
  onLinked,
  onSkip,
}: {
  onLinked: (link: PortalLink) => void;
  onSkip: () => void;
}) {
  const [accountNumber, setAccountNumber] = useState("");
  const [meterNumber, setMeterNumber] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (isLoading) return;
    setIsLoading(true);
    setError("");

    try {
      const link = await createLink(accountNumber.trim(), meterNumber.trim());
      onLinked(link);
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 404) {
          setError("We couldn't find an active connection with that account and meter number.");
        } else if (err.status === 409) {
          setError("This meter is already linked to another account.");
        } else {
          setError(err.message);
        }
      } else {
        setError("Something went wrong. Please try again.");
      }
    } finally {
      setIsLoading(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h2 className="font-semibold text-xl tracking-tight">Link Your Meter</h2>
        <p className="text-muted-foreground text-sm">
          Find these on your latest bill. Linking lets us show your bills and
          usage here.
        </p>
      </div>

      <form className="space-y-4" onSubmit={handleSubmit}>
        <div className="space-y-2">
          <label className="font-medium text-sm" htmlFor="account-number">
            Account Number
          </label>
          <div className="relative">
            <Input
              autoComplete="off"
              className="h-10 pl-9 text-sm"
              id="account-number"
              maxLength={20}
              name="account_number"
              onChange={(e) => setAccountNumber(e.target.value)}
              placeholder="e.g. GW-000123"
              required
              value={accountNumber}
            />
            <Search
              aria-hidden="true"
              className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
          </div>
        </div>

        <div className="space-y-2">
          <label className="font-medium text-sm" htmlFor="meter-number">
            Meter Number
          </label>
          <div className="relative">
            <Input
              autoComplete="off"
              className="h-10 pl-9 text-sm"
              id="meter-number"
              maxLength={20}
              name="meter_number"
              onChange={(e) => setMeterNumber(e.target.value)}
              placeholder="e.g. MTR-001234"
              required
              value={meterNumber}
            />
            <Search
              aria-hidden="true"
              className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
          </div>
        </div>

        {error && <p className="text-destructive text-sm" role="alert">{error}</p>}

        <Button
          className="h-10 w-full text-sm"
          disabled={isLoading}
          type="submit"
        >
          {isLoading ? (
            <Loader2 aria-hidden className="mr-1 h-4 w-4 animate-spin" />
          ) : null}
          Link My Meter
        </Button>
      </form>

      <div className="text-center">
        <button
          type="button"
          onClick={onSkip}
          className="text-sm text-muted-foreground underline underline-offset-4 hover:text-primary transition-colors"
        >
          I&apos;ll do this later
        </button>
      </div>
    </div>
  );
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
  const { isAuthenticated, ready, user, logout, updateProfile } = useAuth();

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
    if (ready && !isAuthenticated) {
      router.replace("/auth");
    }
  }, [ready, isAuthenticated, router]);

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

      <div className="relative z-10 mx-auto flex min-h-screen w-full max-w-md flex-col px-6 pb-12 md:max-w-2xl lg:max-w-4xl">
        <DashboardHeader
          userName={user?.name}
          userEmail={user?.email}
          onLogout={() => logout()}
        />

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
                  onComplete={async ({ username, avatarId }) => {
                    try {
                      await updateProfile(username, avatarId);
                      setProfileError("");
                      setStep(STEP_LINK);
                    } catch (err) {
                      setProfileError(
                        err instanceof Error ? err.message : "Couldn't save your profile."
                      );
                    }
                  }}
                />
              )}

              {step === STEP_PROFILE && profileError && (
                <p className="mt-4 text-destructive text-sm text-center" role="alert">
                  {profileError}
                </p>
              )}

              {step === STEP_LINK && (
                <div className="rounded-xl border border-border bg-card p-8">
                  <LinkMeterStep
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
