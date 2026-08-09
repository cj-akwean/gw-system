import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import DashboardPage from "@/app/dashboard/page";

const mockReplace = vi.fn();
const mockLogout = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
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

vi.mock("@/components/portal/bills-list", () => ({
  BillsList: () => <div>bills</div>,
}));

vi.mock("@/components/portal/link-meter-prompt", () => ({
  LinkMeterPrompt: () => <div>link-prompt</div>,
}));

let mockUseAuth: () => {
  isAuthenticated: boolean;
  ready: boolean;
  user: { name: string; email: string } | null;
  logout: () => void;
};

describe("DashboardPage guard", () => {
  beforeEach(() => {
    mockReplace.mockReset();
    mockLogout.mockReset();
  });

  it("renders nothing until auth state is ready", () => {
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: false,
      user: { name: "Maria", email: "maria@example.com" },
      logout: mockLogout,
    });

    render(<DashboardPage />);

    expect(screen.queryByText("bills")).not.toBeInTheDocument();
  });

  it("redirects to /auth when not authenticated", () => {
    mockUseAuth = () => ({
      isAuthenticated: false,
      ready: true,
      user: null,
      logout: mockLogout,
    });

    render(<DashboardPage />);

    expect(mockReplace).toHaveBeenCalledWith("/auth");
  });

  it("renders the dashboard for an authenticated user", () => {
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: true,
      user: { name: "Maria", email: "maria@example.com" },
      logout: mockLogout,
    });

    render(<DashboardPage />);

    expect(screen.getByText("bills")).toBeInTheDocument();
    expect(screen.getByText("Maria")).toBeInTheDocument();
    expect(screen.getByText("maria@example.com")).toBeInTheDocument();
  });
});