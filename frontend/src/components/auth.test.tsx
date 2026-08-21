import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { AuthPage } from "@/components/auth";

const mockLogin = vi.fn();
const mockSignup = vi.fn();

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ login: mockLogin, signup: mockSignup, loginWithGoogle: vi.fn() }),
}));

describe("AuthPage signup mode", () => {
  beforeEach(() => {
    mockLogin.mockReset();
    mockSignup.mockReset();
    mockLogin.mockResolvedValue(undefined);
    mockSignup.mockResolvedValue(undefined);
  });

  it("collects only email and password", () => {
    render(<AuthPage mode="signup" />);

    expect(screen.getByPlaceholderText("your.email@example.com")).toBeInTheDocument();
    expect(screen.getByPlaceholderText("Password")).toBeInTheDocument();
    expect(screen.queryByPlaceholderText("Your Name")).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText("Confirm Password")).not.toBeInTheDocument();
  });

  it("calls signup with email and password", async () => {
    render(<AuthPage mode="signup" />);

    fireEvent.change(screen.getByPlaceholderText("your.email@example.com"), {
      target: { value: "new@example.com" },
    });
    fireEvent.change(screen.getByPlaceholderText("Password"), {
      target: { value: "secret123" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Create Account" }));

    await waitFor(() => {
      expect(mockSignup).toHaveBeenCalledWith("new@example.com", "secret123");
    });
    expect(mockLogin).not.toHaveBeenCalled();
  });

  it("shows the signup error message", async () => {
    mockSignup.mockRejectedValue(
      new Error("An account with this email already exists.")
    );

    render(<AuthPage mode="signup" />);

    fireEvent.change(screen.getByPlaceholderText("your.email@example.com"), {
      target: { value: "taken@example.com" },
    });
    fireEvent.change(screen.getByPlaceholderText("Password"), {
      target: { value: "secret123" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Create Account" }));

    expect(
      await screen.findByText("An account with this email already exists.")
    ).toBeInTheDocument();
    const error = screen.getByText(
      "An account with this email already exists."
    );
    const emailInput = screen.getByPlaceholderText("your.email@example.com");
    expect(
      error.compareDocumentPosition(emailInput) &
        Node.DOCUMENT_POSITION_PRECEDING
    ).toBeTruthy();
  });

  it("keeps calling login in login mode", async () => {
    render(<AuthPage mode="login" />);

    fireEvent.change(screen.getByPlaceholderText("your.email@example.com"), {
      target: { value: "jane@example.com" },
    });
    fireEvent.change(screen.getByPlaceholderText("Password"), {
      target: { value: "secret123" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Login with Email" }));

    await waitFor(() => {
      expect(mockLogin).toHaveBeenCalledWith("jane@example.com", "secret123");
    });
    expect(mockSignup).not.toHaveBeenCalled();
  });
});
