import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import SettingsPage from "@/app/settings/page";

const mockReplace = vi.fn();
const mockPush = vi.fn();
const mockLogout = vi.fn();
const mockUpdateProfile = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: mockReplace, push: mockPush }),
}));

let mockUseAuth: () => {
  isAuthenticated: boolean;
  ready: boolean;
  user: { name: string | null; email: string; avatar_id: number | null } | null;
  logout: () => Promise<void>;
  updateProfile: (name: string, avatarId: number) => Promise<void>;
};

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => mockUseAuth(),
}));

vi.mock("@/components/portal/dashboard-header", () => ({
  DashboardHeader: ({ user }: { user: { name: string | null } | null }) => (
    <div>
      <span>header</span>
      <span>{user?.name}</span>
    </div>
  ),
}));

vi.mock("@/components/kokonutui/avatar-picker", () => ({
  default: ({
    onComplete,
    initialUsername,
    initialAvatarId,
  }: {
    onComplete?: (data: { username: string; avatarId: number }) => void;
    initialUsername?: string;
    initialAvatarId?: number;
  }) => (
    <div data-testid="profile-setup">
      <span>{initialUsername}</span>
      <span>{initialAvatarId}</span>
      <button
        type="button"
        onClick={() => onComplete?.({ username: "AquaFan", avatarId: 3 })}
      >
        save-profile
      </button>
    </div>
  ),
}));

vi.mock("@/components/portal/link-meter-form", () => ({
  LinkMeterForm: () => <div data-testid="link-meter-form">link-meter-form</div>,
}));

const linkPayload = {
  id: 5,
  status: "active",
  service_connection: {
    id: 2,
    account_number: "GW-0001",
    meter_number: "MTR-0001",
    registered_name: "Maria Santos",
    barangay: { name: "Poblacion" },
  },
};

function jsonResponse(body: unknown, ok = true, status = 200): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

let fetchSpy: ReturnType<typeof vi.fn>;

function seedFetch(links: unknown[]): void {
  fetchSpy = vi.fn((url: string, init?: RequestInit) => {
    if (init?.method === "DELETE") {
      return Promise.resolve(jsonResponse({ message: "Link revoked" }));
    }
    return Promise.resolve(jsonResponse(links));
  });
  vi.stubGlobal("fetch", fetchSpy);
}

function authedUser() {
  return {
    isAuthenticated: true,
    ready: true,
    user: { name: "Maria", email: "maria@example.com", avatar_id: 2 },
    logout: mockLogout,
    updateProfile: mockUpdateProfile,
  };
}

describe("SettingsPage", () => {
  beforeEach(() => {
    mockReplace.mockReset();
    mockPush.mockReset();
    mockLogout.mockReset();
    mockUpdateProfile.mockReset();
    mockUpdateProfile.mockResolvedValue(undefined);
    localStorage.setItem("auth", JSON.stringify({ token: "token-1", user: {} }));
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    localStorage.clear();
  });

  it("redirects to /auth when not authenticated", () => {
    mockUseAuth = () => ({
      isAuthenticated: false,
      ready: true,
      user: null,
      logout: mockLogout,
      updateProfile: mockUpdateProfile,
    });

    render(<SettingsPage />);

    expect(mockReplace).toHaveBeenCalledWith("/auth");
  });

  it("lists linked meters and shows the link form", async () => {
    seedFetch([linkPayload]);
    mockUseAuth = () => authedUser();

    render(<SettingsPage />);

    expect(
      await screen.findByText("GW-0001 · MTR-0001")
    ).toBeInTheDocument();
    expect(screen.getByText("Maria Santos · Poblacion")).toBeInTheDocument();
    expect(screen.getByTestId("link-meter-form")).toBeInTheDocument();
    expect(screen.getByTestId("profile-setup")).toBeInTheDocument();
  });

  it("unlinks a meter after holding the hold button", async () => {
    seedFetch([linkPayload]);
    mockUseAuth = () => authedUser();

    render(<SettingsPage />);

    const holdButton = await screen.findByRole("button", {
      name: "Unlink GW-0001",
    });
    fireEvent.mouseDown(holdButton);
    await new Promise((resolve) => setTimeout(resolve, 1700));
    fireEvent.mouseUp(holdButton);

    await waitFor(() => {
      expect(screen.queryByText("GW-0001 · MTR-0001")).not.toBeInTheDocument();
    });
    const deleteCall = fetchSpy.mock.calls.find(
      ([, init]) => init?.method === "DELETE"
    ) as [string, RequestInit];
    expect(deleteCall[0]).toBe("http://127.0.0.1:8000/api/links/5");
  });

  it("does not unlink when the hold is released early", async () => {
    seedFetch([linkPayload]);
    mockUseAuth = () => authedUser();

    render(<SettingsPage />);

    const holdButton = await screen.findByRole("button", {
      name: "Unlink GW-0001",
    });
    fireEvent.mouseDown(holdButton);
    await new Promise((resolve) => setTimeout(resolve, 100));
    fireEvent.mouseUp(holdButton);

    expect(screen.getByText("GW-0001 · MTR-0001")).toBeInTheDocument();
    const deleteCall = fetchSpy.mock.calls.find(
      ([, init]) => init?.method === "DELETE"
    );
    expect(deleteCall).toBeUndefined();
  });

  it("shows a confirmation when saving the profile", async () => {
    seedFetch([]);
    mockUseAuth = () => authedUser();

    render(<SettingsPage />);

    fireEvent.click(await screen.findByText("save-profile"));

    expect(await screen.findByText("Profile saved.")).toBeInTheDocument();
    expect(mockUpdateProfile).toHaveBeenCalledWith("AquaFan", 3);
  });
});
