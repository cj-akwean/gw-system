import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { GooglePayButton } from "@/components/portal/google-pay-button";

const onToken = vi.fn();

class FakePaymentsClient {
  static instances: FakePaymentsClient[] = [];
  static readyResult = { resultType: "CAN_PAY" };
  environment?: string;
  isReadyToPay: ReturnType<typeof vi.fn>;
  createButton: ReturnType<typeof vi.fn>;
  loadPaymentData: ReturnType<typeof vi.fn>;

  constructor(options?: { environment?: string }) {
    this.environment = options?.environment;
    this.isReadyToPay = vi.fn().mockResolvedValue(FakePaymentsClient.readyResult);
    // Wire the onClick so clicking the mounted button drives the real
    // component's handleClick → loadPaymentData.
    this.createButton = vi.fn((opts: { onClick: () => void }) => {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = "Google Pay";
      button.addEventListener("click", () => opts.onClick());
      return button;
    });
    this.loadPaymentData = vi.fn();
    FakePaymentsClient.instances.push(this);
  }
}

function installFakeClient() {
  Object.defineProperty(window, "google", {
    configurable: true,
    writable: true,
    value: {
      payments: {
        api: {
          PaymentsClient: FakePaymentsClient,
        },
      },
    },
  });
}

async function latestClient() {
  await vi.waitFor(() => {
    expect(FakePaymentsClient.instances.length).toBeGreaterThan(0);
  });
  return FakePaymentsClient.instances.at(-1) as FakePaymentsClient;
}

const paymentDataFixture = () => ({
  paymentMethodData: {
    tokenizationData: { type: "PAYMENT_GATEWAY_TOKEN", token: "tok_gpay_123" },
    info: {
      cardDetails: "4111",
      cardNetwork: "VISA",
      email: "maria@example.com",
      billingAddress: {
        name: "Maria Santos",
        address1: "123 Purok 3",
        locality: "Guinobatan",
        administrativeArea: "Albay",
        postalCode: "4503",
        countryCode: "PH",
      },
    },
  },
});

async function mountButton() {
  const host = await screen.findByTestId("google-pay-button");
  await vi.waitFor(() => {
    const button = host.querySelector("button");
    expect(button).not.toBeNull();
  });
  return host.querySelector("button") as HTMLButtonElement;
}

describe("GooglePayButton", () => {
  beforeEach(() => {
    FakePaymentsClient.instances = [];
    FakePaymentsClient.readyResult = { resultType: "CAN_PAY" };
    process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY = "pk_test_dummy_key_123";
    onToken.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
    delete (window as { google?: unknown }).google;
  });

  it("renders unavailable when the Google Pay script API is missing", async () => {
    vi.useFakeTimers();
    // No window.google, and the injected script never loads — the 8s loader
    // timeout drives the unavailable state instead of hanging the screen.
    render(<GooglePayButton onToken={onToken} amount={150} />);

    await act(async () => {
      vi.advanceTimersByTime(8_100);
      for (let i = 0; i < 10; i++) await Promise.resolve();
    });

    expect(screen.getByTestId("google-pay-unavailable")).toBeInTheDocument();
    expect(screen.queryByTestId("google-pay-button")).not.toBeInTheDocument();
    expect(screen.getByText(/isn't available/i)).toBeInTheDocument();
  });

  it("renders unavailable when the public key is missing without throwing", async () => {
    delete process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY;

    render(<GooglePayButton onToken={onToken} amount={150} />);

    expect(await screen.findByTestId("google-pay-unavailable")).toBeInTheDocument();
    expect(screen.queryByTestId("google-pay-button")).not.toBeInTheDocument();
  });

  it("renders unavailable when isReadyToPay cannot pay", async () => {
    installFakeClient();
    FakePaymentsClient.readyResult = { resultType: "NO_PAYMENT_METHODS" };

    render(<GooglePayButton onToken={onToken} amount={150} />);

    expect(await screen.findByTestId("google-pay-unavailable")).toBeInTheDocument();
    expect(screen.queryByTestId("google-pay-button")).not.toBeInTheDocument();
    expect((await latestClient()).loadPaymentData).not.toHaveBeenCalled();
  });

  it("mounts the button and flows the token out on tap", async () => {
    installFakeClient();

    render(<GooglePayButton onToken={onToken} amount={150} />);
    const button = await mountButton();
    const client = await latestClient();
    client.loadPaymentData.mockResolvedValue(paymentDataFixture());

    await act(async () => {
      fireEvent.click(button);
      await Promise.resolve();
    });

    expect(onToken).toHaveBeenCalledWith({
      token: "tok_gpay_123",
      billing: { name: "Maria Santos", email: "maria@example.com" },
    });
  });

  it("surfaces an inline error on a loadPaymentData failure but stays silent on cancel", async () => {
    installFakeClient();

    render(<GooglePayButton onToken={onToken} amount={150} />);
    const button = await mountButton();
    const client = await latestClient();
    client.loadPaymentData.mockRejectedValue({
      statusCode: 6,
      statusMessage: "INTERNAL_ERROR",
    });

    await act(async () => {
      fireEvent.click(button);
      await Promise.resolve();
    });

    expect(screen.getByTestId("google-pay-error")).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent(/couldn't complete/i);
    expect(onToken).not.toHaveBeenCalled();

    // A user-cancelled payment is silent.
    client.loadPaymentData.mockRejectedValue({
      statusCode: 11,
      statusMessage: "CANCELED",
    });
    await act(async () => {
      fireEvent.click(button);
      await Promise.resolve();
    });
    expect(screen.queryByTestId("google-pay-error")).not.toBeInTheDocument();
    expect(onToken).not.toHaveBeenCalled();
  });

  it("guards the click while disabled", async () => {
    installFakeClient();

    render(<GooglePayButton onToken={onToken} amount={150} disabled />);
    const button = screen.getByTestId("google-pay-button") as HTMLButtonElement;
    expect(button).toBeDisabled();
    const client = await latestClient();

    await act(async () => {
      fireEvent.click(button);
      await Promise.resolve();
    });

    expect(client.loadPaymentData).not.toHaveBeenCalled();
    expect(onToken).not.toHaveBeenCalled();
  });

  it("derives the environment and gatewayMerchantId from the public key", async () => {
    installFakeClient();

    render(<GooglePayButton onToken={onToken} amount={150} />);
    const client = await latestClient();
    client.loadPaymentData.mockResolvedValue(paymentDataFixture());
    await act(async () => {
      fireEvent.click(await mountButton());
      await Promise.resolve();
    });

    expect(client.environment).toBe("TEST");
    const request = client.loadPaymentData.mock.calls[0][0] as {
      merchantInfo: { merchantName: string; merchantId: string };
      transactionInfo: { totalPrice: string; currencyCode: string };
      allowedPaymentMethods: Array<{
        parameters: { allowedAuthMethods: string[]; allowedCardNetworks: string[] };
        tokenizationSpecification: { parameters: { gatewayMerchantId: string } };
      }>;
    };
    expect(request.allowedPaymentMethods[0].tokenizationSpecification.parameters.gatewayMerchantId).toBe(
      "pk_test_dummy_key_123"
    );
    expect(request.allowedPaymentMethods[0].parameters.allowedCardNetworks).toEqual([
      "VISA",
      "MASTERCARD",
    ]);
    expect(request.merchantInfo.merchantName).toBe("Guinobatan Waterworks");
    expect(request.transactionInfo).toMatchObject({
      totalPrice: "150.00",
      currencyCode: "PHP",
    });
  });

  it("shows the simulate link in the unavailable state (test key + onSimulate)", async () => {
    installFakeClient();
    FakePaymentsClient.readyResult = { resultType: "NO_PAYMENT_METHODS" };
    const onSimulate = vi.fn();

    render(<GooglePayButton onToken={onToken} amount={150} onSimulate={onSimulate} />);

    expect(await screen.findByTestId("google-pay-unavailable")).toBeInTheDocument();
    const simulate = screen.getByTestId("google-pay-simulate");
    expect(simulate).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(simulate);
      await Promise.resolve();
    });
    expect(onSimulate).toHaveBeenCalledTimes(1);
  });

  it("shows the simulate link in the ready state and invokes onSimulate on click", async () => {
    installFakeClient();
    const onSimulate = vi.fn();

    render(<GooglePayButton onToken={onToken} amount={150} onSimulate={onSimulate} />);
    await mountButton();

    const simulate = screen.getByTestId("google-pay-simulate");
    expect(simulate).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(simulate);
      await Promise.resolve();
    });
    expect(onSimulate).toHaveBeenCalledTimes(1);
  });

  it("hides the simulate link for a live key", async () => {
    installFakeClient();
    process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY = "pk_live_dummy_key_456";

    render(<GooglePayButton onToken={onToken} amount={150} onSimulate={vi.fn()} />);
    await mountButton();

    expect(screen.queryByTestId("google-pay-simulate")).not.toBeInTheDocument();
  });

  it("hides the simulate link without an onSimulate prop", async () => {
    installFakeClient();

    render(<GooglePayButton onToken={onToken} amount={150} />);
    await mountButton();

    expect(screen.queryByTestId("google-pay-simulate")).not.toBeInTheDocument();
  });

  it("hides the simulate link while disabled", async () => {
    installFakeClient();

    render(<GooglePayButton onToken={onToken} amount={150} onSimulate={vi.fn()} disabled />);
    screen.getByTestId("google-pay-button");

    expect(screen.queryByTestId("google-pay-simulate")).not.toBeInTheDocument();
  });
});