from durable_workflow import workflow


@workflow.defn(name="update-key-consumer")
class WaitingWorkflow:
    def __init__(self):
        self.count = 0
        self.finished = False

    @workflow.update("add")
    def add(self, amount: int) -> dict:
        self.count += amount
        return self.result()

    @workflow.update("other")
    def other(self, amount: int) -> dict:
        return self.add(amount)

    @workflow.signal("finish")
    def finish(self):
        self.finished = True

    def run(self, ctx):
        yield ctx.wait_condition(lambda: self.finished, key="finish")
        return self.result()

    def result(self):
        return {"count": self.count, "typed": [9223372036854775807, None, "Київ"]}
