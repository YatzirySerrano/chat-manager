import type {
    DeletionCopy,
    DeletionStep,
} from '@/composables/useRecordDeletion';

/*
 * Confirmation texts of the logical deletion and restoration of the records
 * that are deleted from more than one screen (list and detail page). They
 * explain the operational consequences; nothing is destroyed.
 */

/** "Su miembro conserva" / "Sus 3 miembros conservan". */
function possessive(
    total: number | undefined,
    one: string,
    many: string,
): string {
    return total === 1 ? `Su ${one}` : `Sus ${total ?? 0} ${many}`;
}

export function departmentDeletionCopy(
    department: { name: string; members_count?: number },
    step: DeletionStep,
): DeletionCopy {
    return step === 'delete'
        ? {
              title: `¿Eliminar el departamento ${department.name}?`,
              description: `Dejará de recibir y atender conversaciones. ${possessive(department.members_count, 'miembro', 'miembros')} ${department.members_count === 1 ? 'conserva' : 'conservan'} la membresía (sin efecto mientras esté eliminado) y el historial mantiene el departamento original. Podrás restaurarlo.`,
          }
        : {
              title: `¿Restaurar el departamento ${department.name}?`,
              description:
                  'Volverá a operar con los miembros que conservó. Revisa su configuración antes de dirigirle conversaciones.',
          };
}

export function connectionDeletionCopy(
    connection: { name: string; conversations_count?: number },
    step: DeletionStep,
): DeletionCopy {
    return step === 'delete'
        ? {
              title: `¿Eliminar la conexión ${connection.name}?`,
              description: `La conexión se desactivará y no enviará ni recibirá mensajes. ${possessive(connection.conversations_count, 'conversación', 'conversaciones')} y su historial se conservan, y su Phone number ID queda reservado. Al restaurarla quedará inactiva y deberás verificar sus credenciales de nuevo.`,
          }
        : {
              title: `¿Restaurar la conexión ${connection.name}?`,
              description:
                  'Quedará inactiva y con las credenciales pendientes de verificar: verifícalas con Meta y actívala cuando esté lista.',
          };
}

export function contactDeletionCopy(
    contact: { name: string },
    step: DeletionStep,
): DeletionCopy {
    return step === 'delete'
        ? {
              title: `¿Eliminar a ${contact.name}?`,
              description:
                  'El contacto dejará de aparecer en las listas. Sus conversaciones, notas e historial se conservan; si vuelve a escribir, se seguirá usando este mismo contacto. Podrás restaurarlo.',
          }
        : {
              title: `¿Restaurar a ${contact.name}?`,
              description:
                  'Volverá a aparecer en las listas con todo su historial.',
          };
}
