"use client";

import {
  useEffect,
  useId,
  useRef,
  useState,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
} from "react";
import { Info } from "lucide-react";
import { Popover } from "radix-ui";

import { cn } from "@/lib/utils";

interface InfoTipProps {
  content: ReactNode;
  /** Accessible name for the ⓘ trigger. Defaults to "More information". */
  label?: string;
  side?: "top" | "right" | "bottom" | "left";
  className?: string;
  contentClassName?: string;
  /** Hover-to-open delay in ms (default 200). Exposed for tests. */
  openDelayMs?: number;
  /** Leave-to-close grace period in ms (default 120). Exposed for tests. */
  closeDelayMs?: number;
}

const HOVER_OPEN_DELAY_MS = 200;
const HOVER_CLOSE_GRACE_MS = 120;

/**
 * One consistent info-tip pattern for the whole frontend:
 * - Desktop (mouse pointer): hover opens the tooltip after a short delay,
 *   leaving the trigger closes it. Clicking with a mouse also works and stays
 *   open until the pointer leaves.
 * - Touch (tap): opens a popover; tap again, tap outside, or press Escape to
 *   close. Never a ported hover tooltip — hover state doesn't exist on touch.
 *
 * Pointer-type gating (not matchMedia): pointerenter/pointerleave only react
 * to `pointerType === "mouse"`, so synthetic hover events on tap never fire
 * the hover path, and no SSR/hydration branching is needed.
 *
 * See docs/insights/frontend-design.md → InfoTip for the full spec.
 */
export function InfoTip({
  content,
  label = "More information",
  side = "bottom",
  className,
  contentClassName,
  openDelayMs = HOVER_OPEN_DELAY_MS,
  closeDelayMs = HOVER_CLOSE_GRACE_MS,
}: InfoTipProps) {
  const [open, setOpen] = useState(false);
  const tipId = useId();
  const contentRef = useRef<HTMLDivElement>(null);
  const openTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const closeTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(
    () => () => {
      if (openTimerRef.current !== null) clearTimeout(openTimerRef.current);
      if (closeTimerRef.current !== null) clearTimeout(closeTimerRef.current);
    },
    []
  );

  if (content == null || content === "") {
    return null;
  }

  const cancelOpenTimer = () => {
    if (openTimerRef.current !== null) {
      clearTimeout(openTimerRef.current);
      openTimerRef.current = null;
    }
  };

  const cancelCloseTimer = () => {
    if (closeTimerRef.current !== null) {
      clearTimeout(closeTimerRef.current);
      closeTimerRef.current = null;
    }
  };

  const clearTimers = () => {
    cancelOpenTimer();
    cancelCloseTimer();
  };

  const scheduleOpen = () => {
    cancelCloseTimer();
    openTimerRef.current = setTimeout(() => setOpen(true), openDelayMs);
  };

  const scheduleClose = () => {
    cancelOpenTimer();
    closeTimerRef.current = setTimeout(() => setOpen(false), closeDelayMs);
  };

  const handleTriggerPointerLeave = (event: ReactPointerEvent<HTMLButtonElement>) => {
    if (event.pointerType !== "mouse") return;
    const nextTarget = event.relatedTarget as Node | null;
    if (contentRef.current?.contains(nextTarget)) return;
    scheduleClose();
  };

  const handleTriggerPointerEnter = (event: ReactPointerEvent<HTMLButtonElement>) => {
    if (event.pointerType !== "mouse") return;
    scheduleOpen();
  };

  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Trigger asChild>
        <button
          type="button"
          data-testid="info-tip-trigger"
          aria-label={label}
          aria-describedby={open ? tipId : undefined}
          onPointerEnter={handleTriggerPointerEnter}
          onPointerLeave={handleTriggerPointerLeave}
          onClick={clearTimers}
          className={cn(
            "inline-flex size-5 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30",
            className
          )}
        >
          <Info className="size-4" aria-hidden="true" />
        </button>
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          id={tipId}
          role="tooltip"
          ref={contentRef}
          side={side}
          align="center"
          sideOffset={6}
          onOpenAutoFocus={(event) => event.preventDefault()}
          onCloseAutoFocus={(event) => event.preventDefault()}
          onPointerEnter={(event) => {
            if (event.pointerType === "mouse") cancelCloseTimer();
          }}
          onPointerLeave={(event) => {
            if (event.pointerType === "mouse") scheduleClose();
          }}
          className={cn(
            "z-50 max-w-60 rounded-lg border border-border bg-popover px-3 py-2 text-xs leading-relaxed text-popover-foreground shadow-md outline-none",
            "data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95",
            "data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95",
            contentClassName
          )}
        >
          {content}
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}
