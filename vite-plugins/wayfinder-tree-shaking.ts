import type { Plugin } from 'vite-plus';

/**
 * Makes the Wayfinder route helpers (resources/js/routes, resources/js/actions)
 * tree-shakable in production builds.
 *
 * Wayfinder generates every helper as a function followed by top-level
 * property writes (`index.url = …`, `index.form = indexForm`) and a default
 * export built with `Object.assign(x, x)`. The bundler must keep a property
 * write to be safe, so importing one helper pulled in every route of the
 * file: the shared app chunk carried ~260 kB of unused route helpers.
 *
 * This rewrites each helper into one pure expression placed where its last
 * property write was:
 *
 *     export const index = /* @__PURE__ *\/ Object.assign(fn, { url: …, form: indexForm })
 *
 * and `Object.assign(x, x)` (a no-op) into `x`, so unused helpers drop out.
 * The rewrite only runs when every top-level statement matches the generated
 * shapes; anything unexpected leaves the file untouched.
 */
export function wayfinderTreeShaking(): Plugin {
    return {
        name: 'wayfinder-tree-shaking',
        apply: 'build',
        enforce: 'post',
        transform(code, id) {
            const path = id.replace(/\\/g, '/').split('?')[0];

            if (!/\/resources\/js\/(routes|actions)\/.+\.[jt]s$/.test(path)) {
                return null;
            }

            const rewritten = rewrite(code, (source) => this.parse(source));

            return rewritten === null
                ? null
                : { code: rewritten, map: null, moduleSideEffects: false };
        },
    };
}

type Node = {
    type: string;
    start: number;
    end: number;
    [key: string]: any;
};

type Helper = {
    name: string;
    statementIndex: number;
    statement: Node;
    exported: boolean;
    init: Node;
    props: Array<{ key: string; value: Node; statementIndex: number }>;
};

const LAZY_VALUES = new Set([
    'ArrowFunctionExpression',
    'FunctionExpression',
    'Literal',
]);

function isStaticValue(node: Node): boolean {
    switch (node.type) {
        case 'Literal':
        case 'ArrowFunctionExpression':
        case 'FunctionExpression':
            return true;
        case 'ArrayExpression':
            return node.elements.every(
                (element: Node | null) =>
                    element !== null && isStaticValue(element),
            );
        case 'ObjectExpression':
            return node.properties.every(
                (property: Node) =>
                    property.type === 'Property' &&
                    !property.computed &&
                    isStaticValue(property.value),
            );
        default:
            return false;
    }
}

function helperDeclaration(
    statement: Node,
): { exported: boolean; name: string; init: Node } | null {
    const exported = statement.type === 'ExportNamedDeclaration';
    const declaration = exported ? statement.declaration : statement;

    if (
        !declaration ||
        declaration.type !== 'VariableDeclaration' ||
        declaration.declarations.length !== 1
    ) {
        return null;
    }

    const [declarator] = declaration.declarations;

    if (
        declarator.id.type !== 'Identifier' ||
        !declarator.init ||
        !LAZY_VALUES.has(declarator.init.type)
    ) {
        return null;
    }

    return { exported, name: declarator.id.name, init: declarator.init };
}

function propertyWrite(
    statement: Node,
): { target: string; key: string; value: Node } | null {
    const expression = statement.expression;

    if (
        statement.type !== 'ExpressionStatement' ||
        expression.type !== 'AssignmentExpression' ||
        expression.operator !== '=' ||
        expression.left.type !== 'MemberExpression' ||
        expression.left.computed ||
        expression.left.object.type !== 'Identifier' ||
        expression.left.property.type !== 'Identifier'
    ) {
        return null;
    }

    return {
        target: expression.left.object.name,
        key: expression.left.property.name,
        value: expression.right,
    };
}

/** `Object.assign(x, x)` → `x` inside the default export object. */
function selfAssignments(node: Node, found: Node[]): void {
    if (!node || typeof node !== 'object') {
        return;
    }

    if (
        node.type === 'CallExpression' &&
        node.callee.type === 'MemberExpression' &&
        node.callee.object.type === 'Identifier' &&
        node.callee.object.name === 'Object' &&
        node.callee.property.type === 'Identifier' &&
        node.callee.property.name === 'assign' &&
        node.arguments.length === 2 &&
        node.arguments[0].type === 'Identifier' &&
        node.arguments[1].type === 'Identifier' &&
        node.arguments[0].name === node.arguments[1].name
    ) {
        found.push(node);

        return;
    }

    for (const [key, value] of Object.entries(node)) {
        if (key === 'start' || key === 'end') {
            continue;
        }

        if (Array.isArray(value)) {
            value.forEach((child) => selfAssignments(child, found));
        } else if (value && typeof value === 'object' && 'type' in value) {
            selfAssignments(value as Node, found);
        }
    }
}

export function rewrite(
    code: string,
    parse: (code: string) => unknown,
): string | null {
    let program: Node;

    try {
        program = parse(code) as Node;
    } catch {
        return null;
    }

    const body: Node[] = program.body;
    const helpers = new Map<string, Helper>();
    const writeStatements = new Set<number>();
    const otherStatements: number[] = [];

    for (const [index, statement] of body.entries()) {
        const declaration = helperDeclaration(statement);

        if (declaration) {
            if (helpers.has(declaration.name)) {
                return null;
            }

            helpers.set(declaration.name, {
                ...declaration,
                statementIndex: index,
                statement,
                props: [],
            });
            continue;
        }

        const write = propertyWrite(statement);

        if (write) {
            const helper = helpers.get(write.target);

            if (
                !helper ||
                (!isStaticValue(write.value) &&
                    write.value.type !== 'Identifier')
            ) {
                return null;
            }

            helper.props.push({
                key: write.key,
                value: write.value,
                statementIndex: index,
            });
            writeStatements.add(index);
            continue;
        }

        if (
            statement.type === 'ImportDeclaration' ||
            statement.type === 'ExportDefaultDeclaration' ||
            statement.type === 'VariableDeclaration'
        ) {
            otherStatements.push(index);
            continue;
        }

        return null;
    }

    const movedTo = new Map<string, number>();

    for (const helper of helpers.values()) {
        movedTo.set(
            helper.name,
            helper.props.length > 0
                ? helper.props[helper.props.length - 1].statementIndex
                : helper.statementIndex,
        );
    }

    // Moving a helper down is only safe when nothing between its declaration
    // and its new place reads it eagerly: the statements in between must be
    // other helpers or their property writes, and a write whose value is a
    // plain identifier must point to a helper that is already in place.
    for (const helper of helpers.values()) {
        const target = movedTo.get(helper.name)!;

        if (
            otherStatements.some((i) => i > helper.statementIndex && i < target)
        ) {
            return null;
        }

        for (const prop of helper.props) {
            if (prop.value.type !== 'Identifier') {
                continue;
            }

            const referenced = movedTo.get(prop.value.name);

            if (referenced === undefined || referenced >= target) {
                return null;
            }
        }
    }

    const edits: Array<{ start: number; end: number; text: string }> = [];
    const source = (node: Node) => code.slice(node.start, node.end);

    for (const helper of helpers.values()) {
        if (helper.props.length === 0) {
            continue;
        }

        const props = helper.props
            .map((prop) => `${prop.key}: ${source(prop.value)}`)
            .join(',\n');
        const declaration = `${helper.exported ? 'export ' : ''}const ${helper.name} = /* @__PURE__ */ Object.assign(${source(helper.init)}, {\n${props}\n});`;

        edits.push({
            start: helper.statement.start,
            end: helper.statement.end,
            text: '',
        });

        for (const prop of helper.props) {
            const statement = body[prop.statementIndex];
            const isLast = prop === helper.props[helper.props.length - 1];

            edits.push({
                start: statement.start,
                end: statement.end,
                text: isLast ? declaration : '',
            });
        }
    }

    for (const index of otherStatements) {
        const found: Node[] = [];

        selfAssignments(body[index], found);

        for (const call of found) {
            edits.push({
                start: call.start,
                end: call.end,
                text: call.arguments[0].name,
            });
        }
    }

    if (edits.length === 0) {
        return null;
    }

    edits.sort((a, b) => b.start - a.start);

    let output = code;

    for (const edit of edits) {
        output =
            output.slice(0, edit.start) + edit.text + output.slice(edit.end);
    }

    return output;
}
