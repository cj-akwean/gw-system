import { describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { Input } from "@/components/ui/input";

describe("Input password reveal toggle", () => {
  it("renders a toggle button for password inputs", () => {
    render(<Input aria-label="Password" type="password" />);

    expect(
      screen.getByRole("button", { name: "Show password" })
    ).toBeInTheDocument();
  });

  it("switches the input type between password and text", () => {
    render(<Input aria-label="Password" type="password" />);

    const input = screen.getByLabelText("Password") as HTMLInputElement;
    expect(input.type).toBe("password");

    fireEvent.click(screen.getByRole("button", { name: "Show password" }));
    expect(input.type).toBe("text");
    expect(
      screen.getByRole("button", { name: "Hide password" })
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Hide password" }));
    expect(input.type).toBe("password");
  });

  it("keeps the input focused when the toggle is pressed", () => {
    render(<Input aria-label="Password" type="password" />);

    const input = screen.getByLabelText("Password") as HTMLInputElement;
    input.focus();
    fireEvent.mouseDown(screen.getByRole("button", { name: "Show password" }));
    fireEvent.click(screen.getByRole("button", { name: "Show password" }));

    expect(document.activeElement).toBe(input);
  });

  it("does not render a toggle for non-password inputs", () => {
    render(<Input aria-label="Email" type="email" />);

    expect(screen.queryByRole("button", { name: "Show password" })).not.toBeInTheDocument();
  });
});