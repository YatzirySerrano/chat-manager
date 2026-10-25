/**
 * Error texts stored before Meta errors were written in plain Spanish carry
 * a technical tail: " (código 190) Detalle de Meta: Invalid OAuth…". This
 * separates the explanation (shown to everyone) from that tail (shown to
 * administrators under "Detalles técnicos"). Same rules as
 * MetaErrorCatalog::separateTechnicalDetail on the server.
 */
export type SplitError = { message: string; technical: string | null };

export function splitTechnicalDetail(text: string): SplitError {
    const withCode = text.match(
        /^(.*?)\s*\(código (\d+)\)\.?\s*(?:Detalle de Meta:\s*(.*))?$/su,
    );

    if (withCode) {
        const detail = withCode[3] ? ` · ${withCode[3]}` : '';

        return {
            message: withCode[1].trim(),
            technical: `Código ${withCode[2]}${detail}`,
        };
    }

    const withDetail = text.match(/^(.*?)\s*Detalle de Meta:\s*(.*)$/su);

    if (withDetail) {
        return { message: withDetail[1].trim(), technical: withDetail[2] };
    }

    return { message: text, technical: null };
}
