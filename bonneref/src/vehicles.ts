import { mapKnownCar } from './api';
import { carRef } from './route';
import type { AgentVehicle, CarType, Vehicle } from './types';

// Cars the agent identified without a plate live under a "k<carId>-<type>" ref; their identity comes
// from the setVehicle frame and is kept for the tab so a reload of that ref still names the car.
const KEY = 'bonneref.cars';

export function vehicleFromAgent(v: AgentVehicle): Vehicle {
  return {
    plate: v.plate,
    make: v.make,
    model: v.model,
    modelEn: null,
    version: v.engine,
    engineCode: null,
    powerHp: null,
    firstRegistration: null,
    energy: null,
    vin: null,
    carId: v.carId,
    carType: v.carType,
  };
}

function read(): Record<string, Vehicle> {
  try {
    const parsed: unknown = JSON.parse(sessionStorage.getItem(KEY) ?? '{}');
    return typeof parsed === 'object' && parsed !== null ? (parsed as Record<string, Vehicle>) : {};
  } catch {
    return {};
  }
}

export function rememberCar(v: AgentVehicle): string {
  const ref = carRef(v.carId, v.carType);
  try {
    sessionStorage.setItem(KEY, JSON.stringify({ ...read(), [ref]: vehicleFromAgent(v) }));
  } catch {
    /* the car still shows this session, a reload only loses its name */
  }
  return ref;
}

export function carVehicle(carId: number, carType: CarType): Vehicle {
  return read()[carRef(carId, carType)] ?? vehicleFromAgent({ carId, carType, make: null, model: null, engine: null, plate: null });
}

// A bare carId names itself from the server's vehicle index; what the tab remembered wins, and an unknown car stays a bare kType.
export async function resolveCar(carId: number, carType: CarType, read: (carId: number, carType: CarType) => Promise<unknown>): Promise<Vehicle> {
  const remembered = carVehicle(carId, carType);
  if (remembered.make) return remembered;
  try {
    return vehicleFromAgent(mapKnownCar(await read(carId, carType), carId, carType));
  } catch {
    return remembered;
  }
}
