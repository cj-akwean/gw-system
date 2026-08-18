import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import OnboardingPage from "@/app/onboarding/page";

const mockReplace = vi.fn();
const mockPush = vi.fn();
const mockLogout = vi.fn();
const mockUpdateProfile = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: mockReplace, push: mockPush }),
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => mockUseAuth(),
}));

vi.mock("@/components/portal/dashboard-header", () => ({
  DashboardHeader: ({ user, onLogout }: {
    user: { name: string | null; email: string } | null;
    onLogout: () => void;
  }) => (
    <div>
      <span>header</span>
      <span>{user?.name}</span>
      <span>{user?.email}</span>
      <button onClick={onLogout}>logout-btn</button>
    </div>
  ),
}));

let mockUseAuth: () => {
  isAuthenticated: boolean;
  ready: boolean;
  user: { id: number; name: string | null; email: string; avatar_id: number | null } | null;
  logout: () => void;
  updateProfile: (name: string, avatarId: number) => Promise<void>;
};

const linkPayload = {
  id: 5,
  status: "active",
  service_connection: {
    id: 2,
    account_number: "GW-0001",
    meter_number: "MTR-0001",
    registered_name: "Maria Santos",
    barangay: null,
  },
};

function jsonResponse(body: unknown, ok = true, status = 200): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

function seedLinksFetch(links: unknown[], createStatus: number | null = null): void {
  vi.stubGlobal(
    "fetch",
    vi.fn((url: string, init?: RequestInit) => {
      if (init?.method === "POST") {
        if (createStatus === null) {
          return Promise.resolve(jsonResponse(linkPayload, true, 201));
        }
        return Promise.resolve(
          jsonResponse(
            { message: "This meter is already linked to another account." },
            false,
            createStatus
          )
        );
      }
      return Promise.resolve(jsonResponse(links));
    })
  );
}

function seedAuthToken(): void {
  localStorage.setItem("auth", JSON.stringify({ token: "token-1", user: {} }));
}

function freshUser() {
  return { id: 1, name: null, email: "new@example.com", avatar_id: null };
}

describe("OnboardingPage guard", () => {
  beforeEach(() => {
    mockReplace.mockReset();
    mockPush.mockReset();
    mockLogout.mockReset();
    mockUpdateProfile.mockReset();
    mockUpdateProfile.mockResolvedValue(undefined);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("renders nothing until auth state is ready", () => {
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: false,
      user: freshUser(),
      logout: mockLogout,
      updateProfile: mockUpdateProfile,
    });

    render(<OnboardingPage />);

    expect(screen.queryByText(/Pick Your Avatar/i)).not.toBeInTheDocument();
  });

  it("redirects to /auth when not authenticated", () => {
    mockUseAuth = () => ({
      isAuthenticated: false,
      ready: true,
      user: null,
      logout: mockLogout,
      updateProfile: mockUpdateProfile,
    });

    render(<OnboardingPage />);

    expect(mockReplace).toHaveBeenCalledWith("/auth");
  });
});

describe("OnboardingPage wizard", () => {
  beforeEach(() => {
    seedAuthToken();
    mockReplace.mockReset();
    mockPush.mockReset();
    mockLogout.mockReset();
    mockUpdateProfile.mockReset();
    mockUpdateProfile.mockResolvedValue(undefined);
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: true,
      user: freshUser(),
      logout: mockLogout,
      updateProfile: mockUpdateProfile,
    });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("starts at the avatar step for a fresh account", async () => {
    seedLinksFetch([]);

    render(<OnboardingPage />);

    expect(await screen.findByText("Pick Your Avatar")).toBeInTheDocument();
    expect(screen.getAllByText("Create your profile").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Link your meter").length).toBeGreaterThan(0);
  });

  it("saves the profile and moves to the link step", async () => {
    seedLinksFetch([]);

    render(<OnboardingPage />);

    const usernameInput = await screen.findByPlaceholderText("your_username…");
    fireEvent.change(usernameInput, { target: { value: "AquaFan" } });

    const submitButton = screen.getByRole("button", { name: /Get Started/i });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(mockUpdateProfile).toHaveBeenCalledWith("AquaFan", 1, null);
    });
    expect(await screen.findByText("Link Your Meter")).toBeInTheDocument();
  });

  it("links a meter successfully and shows the all-set step", async () => {
    seedLinksFetch([]);

    render(<OnboardingPage />);

    const usernameInput = await screen.findByPlaceholderText("your_username…");
    fireEvent.change(usernameInput, { target: { value: "AquaFan" } });
    fireEvent.click(screen.getByRole("button", { name: /Get Started/i }));

    const accountInput = await screen.findByLabelText("Account Number");
    fireEvent.change(accountInput, { target: { value: "GW-0001" } });
    fireEvent.change(screen.getByLabelText("Meter Number"), {
      target: { value: "MTR-0001" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Link My Meter" }));

    expect(await screen.findByText("You're all set!")).toBeInTheDocument();
  });

  it("shows the not-found message for a wrong account + meter combination", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn((url: string, init?: RequestInit) => {
        if (init?.method === "POST") {
          return Promise.resolve(
            jsonResponse(
              { message: "We couldn't find an active connection with that account and meter number." },
              false,
              404
            )
          );
        }
        return Promise.resolve(jsonResponse([]));
      })
    );

    render(<OnboardingPage />);

    const usernameInput = await screen.findByPlaceholderText("your_username…");
    fireEvent.change(usernameInput, { target: { value: "AquaFan" } });
    fireEvent.click(screen.getByRole("button", { name: /Get Started/i }));

    const accountInput = await screen.findByLabelText("Account Number");
    fireEvent.change(accountInput, { target: { value: "GW-9999" } });
    fireEvent.change(screen.getByLabelText("Meter Number"), {
      target: { value: "MTR-9999" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Link My Meter" }));

    expect(
      await screen.findByText(
        "We couldn't find an active connection with that account and meter number."
      )
    ).toBeInTheDocument();
  });

  it("shows the already-linked message on a 409", async () => {
    seedLinksFetch([], 409);

    render(<OnboardingPage />);

    const usernameInput = await screen.findByPlaceholderText("your_username…");
    fireEvent.change(usernameInput, { target: { value: "AquaFan" } });
    fireEvent.click(screen.getByRole("button", { name: /Get Started/i }));

    const accountInput = await screen.findByLabelText("Account Number");
    fireEvent.change(accountInput, { target: { value: "GW-0001" } });
    fireEvent.change(screen.getByLabelText("Meter Number"), {
      target: { value: "MTR-0001" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Link My Meter" }));

    expect(
      await screen.findByText("This meter is already linked to another account.")
    ).toBeInTheDocument();
  });

  it("lets the user skip the link step", async () => {
    seedLinksFetch([]);

    render(<OnboardingPage />);

    const usernameInput = await screen.findByPlaceholderText("your_username…");
    fireEvent.change(usernameInput, { target: { value: "AquaFan" } });
    fireEvent.click(screen.getByRole("button", { name: /Get Started/i }));

    fireEvent.click(await screen.findByText("I'll do this later"));

    expect(await screen.findByText("You're all set!")).toBeInTheDocument();
    expect(
      screen.getByText(/Link your meter from your dashboard anytime/i)
    ).toBeInTheDocument();
  });

  it("navigates to the dashboard from the all-set step", async () => {
    seedLinksFetch([]);

    render(<OnboardingPage />);

    const usernameInput = await screen.findByPlaceholderText("your_username…");
    fireEvent.change(usernameInput, { target: { value: "AquaFan" } });
    fireEvent.click(screen.getByRole("button", { name: /Get Started/i }));

    const accountInput = await screen.findByLabelText("Account Number");
    fireEvent.change(accountInput, { target: { value: "GW-0001" } });
    fireEvent.change(screen.getByLabelText("Meter Number"), {
      target: { value: "MTR-0001" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Link My Meter" }));

    fireEvent.click(await screen.findByRole("button", { name: "Go to My Dashboard" }));

    expect(mockPush).toHaveBeenCalledWith("/dashboard");
  });

  it("resumes at the link step when the avatar is already set", async () => {
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: true,
      user: { id: 1, name: "AquaFan", email: "new@example.com", avatar_id: 2 },
      logout: mockLogout,
      updateProfile: mockUpdateProfile,
    });
    seedLinksFetch([]);

    render(<OnboardingPage />);

    expect(await screen.findByText("Link Your Meter")).toBeInTheDocument();
  });

  it("skips straight to all-set when the profile is complete", async () => {
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: true,
      user: { id: 1, name: "AquaFan", email: "new@example.com", avatar_id: 2 },
      logout: mockLogout,
      updateProfile: mockUpdateProfile,
    });
    seedLinksFetch([linkPayload]);

    render(<OnboardingPage />);

    expect(await screen.findByText(/You're all set.*!/)).toBeInTheDocument();
  });
});
