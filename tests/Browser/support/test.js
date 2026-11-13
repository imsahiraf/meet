const registered = [];

export function test(name, fn) {
    registered.push({ name, fn });
}

export function assert(condition, message) {
    if (!condition) {
        throw new Error(`Assertion failed: ${message}`);
    }
}

export function getRegisteredTests() {
    return registered;
}
