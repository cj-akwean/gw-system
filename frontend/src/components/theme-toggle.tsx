"use client";

import { useRef } from "react";
import { SunIcon } from "@/components/sun-icon";
import { MoonIcon } from "@/components/moon-icon";
import { useTheme } from "@/lib/theme";
import { cn } from "@/lib/utils";

interface ThemeToggleProps {
  className?: string;
}

export function ThemeToggle({ className }: ThemeToggleProps) {
  const { dark, mounted, toggle } = useTheme();
  const ref = useRef<HTMLButtonElement>(null);

  return (
    <button
      ref={ref}
      onClick={() => toggle(ref.current)}
      disabled={!mounted}
      aria-label="Toggle theme"
      className={cn(
        "flex h-11 w-11 items-center justify-center rounded-full border backdrop-blur-md transition-all",
        "cursor-pointer hover:scale-105 disabled:cursor-default disabled:hover:scale-100",
        className
      )}
      style={{
        borderColor: "var(--toggle-border)",
        background: "var(--toggle-bg)",
      }}
    >
      {dark ? (
        <SunIcon size={20} className="text-white" />
      ) : (
        <MoonIcon size={20} className="text-[#333]" />
      )}
    </button>
  );
}
