"use client";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

interface UnsavedChangesDialogProps {
  pending: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  /** Shown when confirming to leave. */
  confirmLabel?: string;
}

/**
 * Confirm dialog rendered while `useUnsavedChanges` has a pending navigation.
 * Composes the shared AlertDialog primitive so the guard is reusable anywhere.
 */
export function UnsavedChangesDialog({
  pending,
  onConfirm,
  onCancel,
  confirmLabel = "Leave",
}: UnsavedChangesDialogProps) {
  return (
    <AlertDialog open={pending} onOpenChange={(open) => !open && onCancel()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Unsaved changes</AlertDialogTitle>
          <AlertDialogDescription>
            You have unsaved changes. Leave anyway?
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel data-testid="unsaved-cancel">Stay</AlertDialogCancel>
          <AlertDialogAction data-testid="unsaved-confirm" onClick={onConfirm}>
            {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
