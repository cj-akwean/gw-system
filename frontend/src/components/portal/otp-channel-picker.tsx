"use client";

import { cn } from "@/lib/utils";

interface OtpChannelPickerProps {
  value: "email" | "sms";
  onChange: (channel: "email" | "sms") => void;
  label: string;
  ariaLabel: string;
}

const OPTIONS: { value: "email" | "sms"; label: string }[] = [
  { value: "email", label: "Email" },
  { value: "sms", label: "SMS" },
];

/**
 * Segmented Email/SMS picker for OTP delivery channels — shared by the
 * settings page (password change) and the forgot-password page so the two
 * never drift apart.
 */
function OtpChannelPicker({ value, onChange, label, ariaLabel }: OtpChannelPickerProps) {
  return (
    <div className="space-y-1.5">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      <div className="flex gap-2" role="radiogroup" aria-label={ariaLabel}>
        {OPTIONS.map((option) => (
          <button
            key={option.value}
            aria-checked={value === option.value}
            className={cn(
              "h-9 rounded-lg border px-4 text-xs font-medium transition-colors",
              value === option.value
                ? "border-foreground/20 bg-muted text-foreground"
                : "border-border text-muted-foreground hover:text-foreground"
            )}
            onClick={() => onChange(option.value)}
            role="radio"
            type="button"
          >
            {option.label}
          </button>
        ))}
      </div>
    </div>
  );
}

export { OtpChannelPicker }