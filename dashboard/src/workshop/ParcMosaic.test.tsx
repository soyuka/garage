// @vitest-environment jsdom
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setCurrentLocale } from "../i18n/locale";
import { messages } from "../i18n/messages";
import type { VehicleSummary } from "../types";
import { EditContext, offlineEdits, type EditHost } from "./edits";
import { ParcMosaic } from "./ParcMosaic";

vi.mock("@react-three/fiber", () => ({ Canvas: () => null }));
vi.mock("@react-three/drei", () => ({
  View: Object.assign(() => null, { Port: () => null }),
  PerspectiveCamera: () => null,
}));
vi.mock("../scene/VehicleBody", () => ({
  MeshErrorBoundary: ({ children }: { children: unknown }) => children,
}));
vi.mock("./CarStage", () => ({
  carEdgeAngle: () => 0,
  FitCamera: () => null,
  LENGTH: 4,
  vehicleLines: () => ({}),
}));
vi.mock("./EdgeModel", () => ({
  EdgeModel: () => null,
  useTokenColor: () => "#000",
}));

(
  globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
).IS_REACT_ACT_ENVIRONMENT = true;

const t = messages("en");
const e = t.workshop.edit;

const vehicle = (id: string, model: string): VehicleSummary =>
  ({
    "@id": id,
    make: "Renault",
    model,
    vehicleType: "car",
    source: "manual",
    usageUnit: "km",
  }) as VehicleSummary;
const VEHICLES = [
  vehicle("/garage/vehicles/v1", "Espace"),
  vehicle("/garage/vehicles/v2", "Clio"),
];

let host: HTMLDivElement;
let root: Root;

function mount(deleteVehicle: (iri: string) => Promise<void>) {
  const editHost: EditHost = {
    edits: { ...offlineEdits('offline'), deleteVehicle },
    open: () => undefined,
    close: () => undefined,
  };
  act(() => {
    root.render(
      <EditContext.Provider value={editHost}>
        <ParcMosaic
          vehicles={VEHICLES}
          bundles={{}}
          now={new Date("2026-11-18")}
          loading={false}
          error={null}
          hrefFor={(iri) => `#${iri}`}
        />
      </EditContext.Provider>,
    );
  });
}

const group = (name: string) =>
  Array.from(host.querySelectorAll<HTMLElement>('[role="group"]')).find(
    (g) => g.getAttribute("aria-label") === name,
  )!;
const button = (scope: HTMLElement, label: string) =>
  Array.from(scope.querySelectorAll("button")).find(
    (b) => b.textContent === label,
  )!;

beforeEach(() => {
  vi.stubGlobal(
    "ResizeObserver",
    class {
      observe() {}
      disconnect() {}
      unobserve() {}
    },
  );
  setCurrentLocale("en");
  host = document.createElement("div");
  document.body.append(host);
  root = createRoot(host);
});

afterEach(() => {
  act(() => root.unmount());
  host.remove();
});

describe("ParcMosaic delete", () => {
  it("deletes the chosen vehicle after a confirmation, without opening it", async () => {
    const deleteVehicle = vi
      .fn<(iri: string) => Promise<void>>()
      .mockResolvedValue(undefined);
    mount(deleteVehicle);
    const opened = vi.fn();
    host.addEventListener("click", (ev) => {
      if ((ev.target as HTMLElement).closest("a")) opened(ev);
    });

    const clio = group("Renault Clio");
    act(() => button(clio, e.deleteVehicle).click());
    expect(deleteVehicle).not.toHaveBeenCalled();
    await act(async () => button(clio, e.confirmDelete).click());

    expect(deleteVehicle).toHaveBeenCalledTimes(1);
    expect(deleteVehicle).toHaveBeenCalledWith("/garage/vehicles/v2");
    expect(opened).not.toHaveBeenCalled();
  });

  it("shows the failure on the row", async () => {
    mount(vi.fn().mockRejectedValue(new Error("boom")));
    const espace = group("Renault Espace");
    act(() => button(espace, e.deleteVehicle).click());
    await act(async () => button(espace, e.confirmDelete).click());
    expect(espace.querySelector('[role="alert"]')?.textContent).toBe(
      e.deleteFailed("boom"),
    );
  });
});
