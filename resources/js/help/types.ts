/**
 * Module-level help shown in the "¿Cómo funciona este módulo?" panel.
 *
 * Every text is written for the people who use the module day to day, in
 * plain Spanish: no internal keys, no technical jargon unless the module is
 * technical by nature (integrations, connections).
 */
export type ModuleHelp = {
    /** Panel title, usually the module name. */
    title: string;
    /** One or two sentences: what the module is for. */
    objective: string;
    /** The main things a person can do here. */
    mainActions: string[];
    /** Typical workflow, in order. */
    workflow: string[];
    /** Fields or columns worth explaining. */
    fields: ModuleHelpItem[];
    /** States or statuses a record can have. */
    states: ModuleHelpItem[];
    /** What permissions allow (and what they do not). */
    permissions: string[];
    /** Real problems people run into and how to solve them. */
    commonErrors: ModuleHelpProblem[];
    /** Short practical advice. */
    tips: string[];
    /** Concrete usage examples. */
    examples: string[];
};

export type ModuleHelpItem = {
    name: string;
    description: string;
};

export type ModuleHelpProblem = {
    problem: string;
    solution: string;
};
