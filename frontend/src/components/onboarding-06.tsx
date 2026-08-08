import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

export type OnboardingStepType = "done" | "in progress" | "open";

export interface OnboardingStep {
  id: number;
  type: OnboardingStepType;
  title: string;
  description: string;
}

interface OnboardingStepsProps {
  steps: OnboardingStep[];
  title: string;
}

export function OnboardingSteps({ steps, title }: OnboardingStepsProps) {
  return (
    <div>
      <h3 className="font-medium text-foreground">{title}</h3>
      <ul className="mt-6 space-y-6">
        {steps.map((step, stepIdx) => (
          <li className="relative flex gap-x-3" key={step.id}>
            <div
              className={cn(
                "absolute top-0 left-0 flex w-6 justify-center",
                stepIdx === steps.length - 1 ? "h-6" : "-bottom-6"
              )}
            >
              <span aria-hidden className="w-px bg-border" />
            </div>
            <div className="flex items-start space-x-2.5">
              <div className="relative flex size-6 flex-none items-center justify-center bg-background">
                {step.type === "done" ? (
                  <Check aria-hidden className="size-5 text-primary" />
                ) : step.type === "in progress" ? (
                  <div
                    aria-hidden
                    className="size-2.5 rounded-full bg-primary ring-4 ring-background"
                  />
                ) : (
                  <div
                    aria-hidden
                    className="size-3 rounded-full border border-border bg-background ring-4 ring-background"
                  />
                )}
              </div>
              <div>
                <p className="mt-0.5 font-medium text-foreground text-sm">
                  {step.title}
                </p>
                <p className="mt-0.5 text-muted-foreground text-sm leading-6">
                  {step.description}
                </p>
              </div>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
