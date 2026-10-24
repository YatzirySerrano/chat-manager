import type { SignedIdentity, WidgetInstance, WidgetOptions } from './types';

/**
 * Optional jQuery adapter, bundled with the loader and with no dependency on
 * jQuery: $.fn.chatManager('init', opts) mounts the widget inside the first
 * element (the page holds a single instance), and 'open' | 'close' |
 * 'toggle' | 'destroy' | 'identify' | 'logout' control it. Only the element
 * the widget was mounted in keeps the instance, in a WeakMap (never in
 * $.data): an init that returns an already existing instance is not
 * attached to the element.
 * Registered automatically when window.jQuery exists when the loader runs,
 * or later with ChatManager.jquery($).
 */
type JQueryLike = {
    fn?: Record<string, unknown>;
};

type JQueryCollection = {
    each(callback: (this: Element) => void): JQueryCollection;
};

type Command =
    | 'init'
    | 'open'
    | 'close'
    | 'toggle'
    | 'destroy'
    | 'identify'
    | 'logout';

export function createJQueryAdapter(
    init: (options: WidgetOptions) => WidgetInstance | null,
    current: () => WidgetInstance | null,
): (jquery: unknown) => boolean {
    const instances = new WeakMap<Element, WidgetInstance>();

    return (jquery: unknown): boolean => {
        const $ = jquery as JQueryLike | null | undefined;

        if (!$ || typeof $.fn !== 'object' || $.fn === null) {
            return false;
        }

        if (typeof $.fn.chatManager === 'function') {
            return true;
        }

        $.fn.chatManager = function chatManager(
            this: JQueryCollection,
            command: Command = 'init',
            argument?: unknown,
        ): JQueryCollection {
            return this.each(function each(this: Element) {
                if (command === 'init') {
                    const existing = current();
                    const instance = init({
                        ...(argument as WidgetOptions),
                        container: this,
                    });

                    if (instance && instance !== existing) {
                        instances.set(this, instance);
                    }

                    return;
                }

                const instance = instances.get(this);

                if (!instance) {
                    return;
                }

                if (command === 'identify') {
                    instance.identify(argument as SignedIdentity);
                } else if (
                    command === 'open' ||
                    command === 'close' ||
                    command === 'toggle' ||
                    command === 'destroy' ||
                    command === 'logout'
                ) {
                    instance[command]();
                }

                if (command === 'destroy') {
                    instances.delete(this);
                }
            });
        };

        return true;
    };
}
