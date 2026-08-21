import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, fireEvent, act } from "@testing-library/react";
import { SwipeButton } from "@/components/ui/swipe-button";

describe("SwipeButton", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  const renderButton = (onComplete: () => void) => {
    const { container } = render(
      <SwipeButton text="Swipe to pay" onSwipeComplete={onComplete} />
    );
    // The native <button> inside the swipe track is the focusable control.
    const button = container.querySelector("button") as HTMLButtonElement;
    return { button };
  };

  it("completes on activation (keyboard path)", () => {
    const onComplete = vi.fn();
    const { button } = renderButton(onComplete);

    fireEvent.click(button);

    expect(onComplete).toHaveBeenCalledTimes(1);
  });

  it("does not complete while already validated", () => {
    const onComplete = vi.fn();
    const { button } = renderButton(onComplete);

    fireEvent.click(button);
    fireEvent.click(button);

    expect(onComplete).toHaveBeenCalledTimes(1);
  });

  it("resets after the validation duration", () => {
    const onComplete = vi.fn();
    const { button } = renderButton(onComplete);

    fireEvent.click(button);
    expect(onComplete).toHaveBeenCalledTimes(1);

    // After validationDuration (2s), the button resets to activatable.
    act(() => {
      vi.advanceTimersByTime(2500);
    });
    fireEvent.click(button);
    expect(onComplete).toHaveBeenCalledTimes(2);
  });
});