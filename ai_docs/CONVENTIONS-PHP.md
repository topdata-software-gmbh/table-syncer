# PHP code style conventions

- the names of a private method should start with an underscore, e.g. `_myPrivateMethod()`
- each class should have a docblock explaining what the class does
- each method should have a docblock explaining what the method does, except setters and getters
- Do NOT add redundant PHPDoc tags to docblocks, e.g. `@return void` or `@param string $foo` without any additional information
- the signature of a method should have type hints for all parameters and the return type
- when injecting services, use constructor property promotion, and use the `private readonly` visibility modifier for the injected service
- use `match` instead of `switch` if possible
- for commands, use attribites, eg: `#[AsCommand(name: 'app:user-list',  description: 'Lists all users in a table format')]`
- one class per file

- The code should be modular, with components logically separated to improve maintainability and reusability
- Avoid placing all code into a single file; instead, organize it into multiple modules or files as appropriate
- Use section comments, starting with `// ----`, explaining key parts of the code
- do not try-catch just to print a fancy error message, fail loudly and early instead to get proper stack traces.
- prefer string concatenation or string interpolation over sprintf, unless really necessary

- Avoid returning $this in setters, no fluent interface / method chaining!

