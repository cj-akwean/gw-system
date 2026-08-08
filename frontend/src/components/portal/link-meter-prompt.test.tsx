import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { LinkMeterPrompt } from "@/components/portal/link-meter-prompt";

const mockPush = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: mockPush }),
}));

function jsonResponse(body: unknown, ok = true, status = 200): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

describe("LinkMeterPrompt", () => {
  beforeEach(() => {
    mockPush.mockReset();
    localStorage.setItem("auth", JSON.stringify({ token: "token-1", user: {} }));
  });

  afterEach(() => {
    localStorage.removeItem("auth");
    vi.unstubAllGlobals();
  });

  it("renders nothing while links are loading", () => {
    vi.stubGlobal("fetch", vi.fn(() => new Promise(() => {})));

    render(<LinkMeterPrompt />);

    expect(screen.queryByText("Link your meter")).not.toBeInTheDocument();
  });

  it("renders the prompt when the user has no links", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse([])));

    render(<LinkMeterPrompt />);

    expect(await screen.findByText("Link your meter")).toBeInTheDocument();
  });

  it("renders nothing when the user already has links", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse([
          {
            id: 5,
            status: "active",
            service_connection: {
              id: 2,
              account_number: "GW-0001",
              meter_number: "MTR-0001",
              registered_name: "Maria Santos",
              barangay: null,
            },
          },
        ])
      )
    );

    render(<LinkMeterPrompt />);

    await waitFor(() => {
      expect(screen.queryByText("Link your meter")).not.toBeInTheDocument();
    });
  });

  it("navigates to /onboarding on click", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse([])));

    render(<LinkMeterPrompt />);

    fireEvent.click(await screen.findByRole("button", { name: "Link Meter" }));

    expect(mockPush).toHaveBeenCalledWith("/onboarding");
  });
});
