import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent, { type UserEvent } from "@testing-library/user-event";
import { InfoTip } from "./info-tip";

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

async function setupUser(): Promise<UserEvent> {
  return userEvent.setup();
}

async function touchTap(user: UserEvent, target: Element): Promise<void> {
  await user.pointer({ keys: "[TouchA>]", target });
  await user.pointer({ keys: "[/TouchA]" });
}

describe("InfoTip", () => {
  it("renders a ⓘ trigger with an accessible label and no content until opened", () => {
    render(<InfoTip content="Explanatory text." label="Why is this here?" />);

    const trigger = screen.getByTestId("info-tip-trigger");
    expect(trigger).toHaveAccessibleName("Why is this here?");
    expect(screen.queryByRole("tooltip")).not.toBeInTheDocument();
  });

  it("opens on hover after the delay and closes on leave (desktop)", async () => {
    const user = await setupUser();
    render(
      <InfoTip content="Explanatory text." openDelayMs={40} closeDelayMs={30} />
    );

    const trigger = screen.getByTestId("info-tip-trigger");
    await user.hover(trigger);

    await wait(10);
    expect(screen.queryByRole("tooltip")).not.toBeInTheDocument();
    await wait(150);
    expect(screen.getByRole("tooltip")).toHaveTextContent("Explanatory text.");

    await user.unhover(trigger);
    await wait(150);
    expect(screen.queryByRole("tooltip")).not.toBeInTheDocument();
  });

  it("never opens when the pointer leaves before the open delay elapses", async () => {
    const user = await setupUser();
    render(<InfoTip content="Explanatory text." />);

    const trigger = screen.getByTestId("info-tip-trigger");
    await user.hover(trigger);
    await wait(60);
    await user.unhover(trigger);
    await wait(450);

    expect(screen.queryByRole("tooltip")).not.toBeInTheDocument();
  });

  it("keeps the tooltip open while the pointer moves from trigger into content", async () => {
    const user = await setupUser();
    render(
      <InfoTip content="Explanatory text." openDelayMs={40} closeDelayMs={30} />
    );

    const trigger = screen.getByTestId("info-tip-trigger");
    await user.hover(trigger);
    await wait(150);

    const tooltip = screen.getByRole("tooltip");
    await user.hover(tooltip);
    await wait(100);
    expect(screen.getByRole("tooltip")).toBeInTheDocument();

    await user.unhover(tooltip);
    await wait(150);
    expect(screen.queryByRole("tooltip")).not.toBeInTheDocument();
  });

  it("toggles open/closed on tap (touch) — synthetic hover events never open it", async () => {
    const user = await setupUser();
    render(<InfoTip content="Explanatory text." />);

    const trigger = screen.getByTestId("info-tip-trigger");
    await touchTap(user, trigger);

    expect(screen.getByRole("tooltip")).toHaveTextContent("Explanatory text.");

    await touchTap(user, trigger);
    expect(screen.queryByRole("tooltip")).not.toBeInTheDocument();
  });

  it("closes when tapping outside the popover (touch)", async () => {
    const user = await setupUser();
    render(<InfoTip content="Explanatory text." />);

    const trigger = screen.getByTestId("info-tip-trigger");
    await touchTap(user, trigger);
    expect(screen.getByRole("tooltip")).toBeInTheDocument();

    await user.click(document.body);
    expect(screen.queryByRole("tooltip")).not.toBeInTheDocument();
  });

  it("closes on Escape (touch)", async () => {
    const user = await setupUser();
    render(<InfoTip content="Explanatory text." />);

    const trigger = screen.getByTestId("info-tip-trigger");
    await touchTap(user, trigger);
    expect(screen.getByRole("tooltip")).toBeInTheDocument();

    await user.keyboard("{Escape}");
    expect(screen.queryByRole("tooltip")).not.toBeInTheDocument();
  });

  it("wires aria-describedby from the trigger to the tooltip while open", async () => {
    const user = await setupUser();
    render(<InfoTip content="Explanatory text." />);

    const trigger = screen.getByTestId("info-tip-trigger");
    expect(trigger).not.toHaveAttribute("aria-describedby");

    await touchTap(user, trigger);
    const tooltip = screen.getByRole("tooltip");
    expect(trigger).toHaveAttribute("aria-describedby", tooltip.id);
  });

  it("renders ReactNode content (links, formatting)", async () => {
    const user = await setupUser();
    render(
      <InfoTip
        content={
          <>
            Read the <a href="/docs">terms</a> for details.
          </>
        }
      />
    );

    await touchTap(user, screen.getByTestId("info-tip-trigger"));
    expect(screen.getByRole("tooltip")).toHaveTextContent("terms");
    expect(screen.getByRole("link", { name: "terms" })).toHaveAttribute(
      "href",
      "/docs"
    );
  });

  it("renders nothing when content is empty", () => {
    render(<InfoTip content="" />);
    expect(screen.queryByTestId("info-tip-trigger")).not.toBeInTheDocument();
    render(<InfoTip content={null} />);
    expect(screen.queryByTestId("info-tip-trigger")).not.toBeInTheDocument();
  });

  it("cleans up pending timers on unmount (no state update after unmount)", async () => {
    const user = await setupUser();
    const errorSpy = vi.spyOn(console, "error").mockImplementation(() => {});
    const { unmount } = render(<InfoTip content="Explanatory text." />);

    await user.hover(screen.getByTestId("info-tip-trigger"));
    unmount();
    await wait(350);

    expect(
      errorSpy.mock.calls.some(([msg]) =>
        String(msg).includes("state update on an unmounted component")
      )
    ).toBe(false);
    errorSpy.mockRestore();
  });
});
