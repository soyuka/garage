import { createContext, useContext } from 'react';
import {
  addTaskPart,
  createMaintenanceRecord,
  createTask,
  createVehicle,
  deleteMaintenanceAttachment,
  deleteMaintenanceRecord,
  deleteServiceRule,
  deleteVehicle,
  getServiceSchedule,
  patchTaskStatus,
  patchVehicle,
  removeTaskPart,
  saveServiceRule,
  updateMaintenanceRecord,
  updateTask,
  updateTaskPart,
  uploadMaintenanceAttachment,
  uploadVehicleDocument,
  type MaintenanceRecordInput,
  type ServiceRuleInput,
  type TaskEdit,
  type VehicleDocumentUpload,
  type VehiclePatch,
} from '../garage';
import type { Iri, MaintenanceAttachment, ServiceAction, ServiceRule, VehicleSummary } from '../types';
import { ruleIdentityChanged, type TaskPartOps } from './editForms';

export interface BillChanges {
  add: File[];
  remove: string[];
}

/** A create that landed before a follow-up write failed: the editor must switch to editing what now exists. */
export class PartialSave extends Error {
  constructor(
    message: string,
    readonly iri: Iri,
  ) {
    super(message);
  }
}

export interface WorkshopEdits {
  saveVisit(vehicleIri: Iri, recordIri: Iri | null, input: MaintenanceRecordInput, bills: BillChanges): Promise<void>;
  deleteVisit(vehicleIri: Iri, recordIri: Iri): Promise<void>;
  /** One bill onto a visit that already exists, at once: it does not wait for the visit form's save. */
  attachBill(vehicleIri: Iri, recordIri: Iri, file: File): Promise<MaintenanceAttachment>;
  uploadDocument(vehicleIri: Iri, input: VehicleDocumentUpload): Promise<void>;
  loadRules(vehicleIri: Iri): Promise<ServiceRule[]>;
  saveRule(vehicleIri: Iri, input: ServiceRuleInput, replaces: ServiceRule | null): Promise<void>;
  deleteRule(vehicleIri: Iri, ruleId: string): Promise<void>;
  saveTask(vehicleIri: Iri, taskIri: Iri | null, fields: TaskEdit & { type: string }, parts: TaskPartOps): Promise<void>;
  dismissTask(vehicleIri: Iri, taskIri: Iri): Promise<void>;
  saveVehicle(vehicleIri: Iri, patch: VehiclePatch): Promise<void>;
  deleteVehicle(vehicleIri: Iri): Promise<void>;
  archiveVehicle(vehicleIri: Iri, archived: boolean): Promise<VehicleSummary>;
  createVehicle(patch: VehiclePatch): Promise<void>;
}

export interface LiveHooks {
  /** Re-read the vehicle's bundle (records, forecast, tasks): the domain frame omits the subresources. */
  refresh: (vehicleIri: Iri) => void;
  created: (vehicle: VehicleSummary) => void;
  deleted: (vehicleIri: Iri) => void;
  updated: (vehicle: VehicleSummary) => void;
  /** A write that cannot be reported inline any more (its form is gone): surface it as a notice. */
  failed: (message: string) => void;
}

async function applyParts(taskIri: Iri, ops: TaskPartOps): Promise<void> {
  for (const id of ops.remove) await removeTaskPart(taskIri, id);
  for (const u of ops.update) await updateTaskPart(taskIri, u.id, u.fields);
  for (const part of ops.add) await addTaskPart(taskIri, part);
}

export function liveEdits(hooks: LiveHooks): WorkshopEdits {
  const after = <T>(vehicleIri: Iri, op: Promise<T>): Promise<T> => op.finally(() => hooks.refresh(vehicleIri));
  return {
    saveVisit: (vehicleIri, recordIri, input, bills) =>
      after(
        vehicleIri,
        (async () => {
          const iri = recordIri ?? (await createMaintenanceRecord(vehicleIri, input)).id;
          if (recordIri) await updateMaintenanceRecord(recordIri, input);
          try {
            for (const url of bills.remove) await deleteMaintenanceAttachment(url);
            for (const file of bills.add) await uploadMaintenanceAttachment(iri, file);
          } catch (e) {
            throw new PartialSave((e as Error).message, iri);
          }
        })(),
      ),
    deleteVisit: (vehicleIri, recordIri) => after(vehicleIri, deleteMaintenanceRecord(recordIri)),
    attachBill: (vehicleIri, recordIri, file) => after(vehicleIri, uploadMaintenanceAttachment(recordIri, file)),
    uploadDocument: (vehicleIri, input) =>
      after(
        vehicleIri,
        uploadVehicleDocument(vehicleIri, input).then(() => undefined),
      ),
    loadRules: (vehicleIri) => getServiceSchedule(vehicleIri),
    saveRule: (vehicleIri, input, replaces) =>
      after(
        vehicleIri,
        (async () => {
          await saveServiceRule(vehicleIri, input);
          if (replaces && ruleIdentityChanged(replaces, input)) await deleteServiceRule(vehicleIri, replaces.id);
        })(),
      ),
    deleteRule: (vehicleIri, ruleId) => after(vehicleIri, deleteServiceRule(vehicleIri, ruleId)),
    saveTask: (vehicleIri, taskIri, fields, parts) =>
      after(
        vehicleIri,
        (async () => {
          const iri = taskIri ?? (await createTask(vehicleIri, fields)).id;
          if (taskIri) await updateTask(taskIri, fields);
          try {
            await applyParts(iri, parts);
          } catch (e) {
            throw new PartialSave((e as Error).message, iri);
          }
        })(),
      ),
    dismissTask: (vehicleIri, taskIri) => after(vehicleIri, patchTaskStatus(taskIri, 'dismissed')),
    saveVehicle: (vehicleIri, patch) =>
      after(
        vehicleIri,
        patchVehicle(vehicleIri, patch).then(() => undefined),
      ),
    deleteVehicle: (vehicleIri) => deleteVehicle(vehicleIri).then(() => hooks.deleted(vehicleIri)),
    archiveVehicle: (vehicleIri, archived) =>
      after(
        vehicleIri,
        patchVehicle(vehicleIri, { archived }).then((vehicle) => {
          hooks.updated(vehicle);
          return vehicle;
        }),
      ),
    createVehicle: async (patch) => {
      const blank = await createVehicle();
      try {
        hooks.created({ ...blank, ...(await patchVehicle(blank['@id'], patch)) });
      } catch (e) {
        // The car exists but blank: open it anyway (its identity is editable there) and say why it is blank.
        hooks.created(blank);
        hooks.failed((e as Error).message);
      }
    },
  };
}

/** The dev fixture has no backend: every write is refused with the same honest line. */
export function offlineEdits(message: string): WorkshopEdits {
  const no = () => Promise.reject(new Error(message));
  return {
    saveVisit: no,
    deleteVisit: no,
    attachBill: no,
    uploadDocument: no,
    loadRules: no,
    saveRule: no,
    deleteRule: no,
    saveTask: no,
    dismissTask: no,
    saveVehicle: no,
    deleteVehicle: no,
    archiveVehicle: no,
    createVehicle: no,
  };
}

export type EditorTarget =
  | { kind: 'visit'; recordId: Iri | null }
  | { kind: 'task'; taskId: Iri | null }
  | { kind: 'rule'; type: string | null; action?: ServiceAction | null; component?: string | null };

export interface EditHost {
  edits: WorkshopEdits;
  open: (target: EditorTarget) => void;
  close: () => void;
}

export const EditContext = createContext<EditHost | null>(null);

export function useEditHost(): EditHost | null {
  return useContext(EditContext);
}
